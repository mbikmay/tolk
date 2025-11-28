<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

// Подключение к базе данных
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка подключения к базе данных: ' . $e->getMessage()]);
    exit;
}

// Получаем действие
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Получаем данные из тела запроса
$input = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {
    
    // ===== МЕРОПРИЯТИЯ =====
    
    case 'getEvents':
        $showHidden = isset($_GET['showHidden']) && $_GET['showHidden'] === 'true';
        
        if ($showHidden) {
            $stmt = $pdo->query("SELECT * FROM events ORDER BY sort_order ASC, id DESC");
        } else {
            $stmt = $pdo->query("SELECT * FROM events WHERE hidden = 0 ORDER BY sort_order ASC, id DESC");
        }
        
        $events = $stmt->fetchAll();
        
        // Получаем участников для каждого мероприятия
        foreach ($events as &$event) {
            $stmt = $pdo->prepare("SELECT * FROM participants WHERE event_id = ? ORDER BY created_at DESC");
            $stmt->execute([$event['id']]);
            $event['participants'] = $stmt->fetchAll();
            $event['hidden'] = (bool)$event['hidden'];
            $event['spots'] = (int)$event['spots'];
            $event['spotsLeft'] = (int)$event['spots_left'];
            $event['priceNote'] = $event['price_note'];
            $event['categoryLabel'] = $event['category_label'];
            $event['registrationLink'] = $event['registration_link'];
        }
        
        echo json_encode($events);
        break;
        
    case 'getEvent':
        $id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->execute([$id]);
        $event = $stmt->fetch();
        
        if ($event) {
            $stmt = $pdo->prepare("SELECT * FROM participants WHERE event_id = ?");
            $stmt->execute([$id]);
            $event['participants'] = $stmt->fetchAll();
            $event['hidden'] = (bool)$event['hidden'];
            $event['spots'] = (int)$event['spots'];
            $event['spotsLeft'] = (int)$event['spots_left'];
            $event['priceNote'] = $event['price_note'];
            $event['categoryLabel'] = $event['category_label'];
            $event['registrationLink'] = $event['registration_link'];
        }
        
        echo json_encode($event ?: null);
        break;
        
    case 'saveEvent':
        if ($method !== 'POST') {
            echo json_encode(['error' => 'Method not allowed']);
            break;
        }
        
        $id = $input['id'] ?? null;
        
        // Проверяем, что id - это число, а не временная метка JS
        if ($id && $id > 1000000000000) {
            $id = null; // Это новое мероприятие с JS timestamp
        }
        
        $data = [
            'title' => $input['title'] ?? '',
            'description' => $input['description'] ?? '',
            'date' => $input['date'] ?? '',
            'time' => $input['time'] ?? '',
            'location' => $input['location'] ?? '',
            'address' => $input['address'] ?? '',
            'price' => $input['price'] ?? '',
            'price_note' => $input['priceNote'] ?? '',
            'category' => $input['category'] ?? 'poetry',
            'category_label' => $input['categoryLabel'] ?? 'Поэзия',
            'emoji' => $input['emoji'] ?? '🎤',
            'image' => $input['image'] ?? null,
            'registration_link' => $input['registrationLink'] ?? '',
            'spots' => (int)($input['spots'] ?? 30),
            'spots_left' => (int)($input['spotsLeft'] ?? 30),
            'hidden' => !empty($input['hidden']) ? 1 : 0
        ];
        
        try {
            if ($id) {
                // Обновление
                $stmt = $pdo->prepare("UPDATE events SET 
                    title=?, description=?, date=?, time=?, location=?, address=?, 
                    price=?, price_note=?, category=?, category_label=?, emoji=?, 
                    image=?, registration_link=?, spots=?, spots_left=?, hidden=?
                    WHERE id=?");
                $stmt->execute([
                    $data['title'], $data['description'], $data['date'], $data['time'],
                    $data['location'], $data['address'], $data['price'], $data['price_note'],
                    $data['category'], $data['category_label'], $data['emoji'], $data['image'],
                    $data['registration_link'], $data['spots'], $data['spots_left'], 
                    $data['hidden'], $id
                ]);
                echo json_encode(['success' => true, 'id' => (int)$id]);
            } else {
                // Создание
                $stmt = $pdo->prepare("INSERT INTO events 
                    (title, description, date, time, location, address, price, price_note, 
                    category, category_label, emoji, image, registration_link, spots, spots_left, hidden)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $data['title'], $data['description'], $data['date'], $data['time'],
                    $data['location'], $data['address'], $data['price'], $data['price_note'],
                    $data['category'], $data['category_label'], $data['emoji'], $data['image'],
                    $data['registration_link'], $data['spots'], $data['spots_left'],
                    $data['hidden']
                ]);
                echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            }
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'deleteEvent':
        if ($method !== 'POST') break;
        $id = $input['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;
        
    case 'toggleEventVisibility':
        if ($method !== 'POST') break;
        $id = $input['id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE events SET hidden = NOT hidden WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    case 'reorderEvents':
        if ($method !== 'POST') break;
        $orders = $input['orders'] ?? []; // Массив вида [{'id': 1, 'order': 0}, {'id': 5, 'order': 1}]
        
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE events SET sort_order = ? WHERE id = ?");
            foreach ($orders as $item) {
                $stmt->execute([$item['order'], $item['id']]);
            }
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
    
    // ===== УЧАСТНИКИ =====
    
    case 'getParticipants':
        $eventId = $_GET['eventId'] ?? 0;
        $stmt = $pdo->prepare("SELECT * FROM participants WHERE event_id = ? ORDER BY created_at DESC");
        $stmt->execute([$eventId]);
        $participants = $stmt->fetchAll();
        
        foreach ($participants as &$p) {
            $p['ticketPrice'] = $p['ticket_price'];
            $p['paymentStatus'] = $p['payment_status'];
        }
        
        echo json_encode($participants);
        break;
    
    case 'saveParticipant':
        if ($method !== 'POST') break;
        
        $id = $input['id'] ?? null;
        $eventId = $input['eventId'] ?? 0;
        
        $data = [
            'name' => $input['name'] ?? '',
            'contact' => $input['contact'] ?? '',
            'ticket_price' => $input['ticketPrice'] ?? '',
            'payment_status' => $input['paymentStatus'] ?? 'pending',
            'note' => $input['note'] ?? ''
        ];
        
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE participants SET name=?, contact=?, ticket_price=?, payment_status=?, note=? WHERE id=?");
                $stmt->execute([$data['name'], $data['contact'], $data['ticket_price'], $data['payment_status'], $data['note'], $id]);
                echo json_encode(['success' => true, 'id' => (int)$id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO participants (event_id, name, contact, ticket_price, payment_status, note) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$eventId, $data['name'], $data['contact'], $data['ticket_price'], $data['payment_status'], $data['note']]);
                echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
            }
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'deleteParticipant':
        if ($method !== 'POST') break;
        $id = $input['id'] ?? 0;
        $stmt = $pdo->prepare("DELETE FROM participants WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;
    
    // ===== ГАЛЕРЕЯ =====
    
    case 'getGallery':
        $stmt = $pdo->query("SELECT * FROM gallery ORDER BY created_at DESC");
        $images = $stmt->fetchAll();
        echo json_encode(array_column($images, 'image'));
        break;
        
    case 'addGalleryImage':
        if ($method !== 'POST') break;
        $image = $input['image'] ?? '';
        
        try {
            $stmt = $pdo->prepare("INSERT INTO gallery (image) VALUES (?)");
            $stmt->execute([$image]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'deleteGalleryImage':
        if ($method !== 'POST') break;
        $image = $input['image'] ?? '';
        $stmt = $pdo->prepare("DELETE FROM gallery WHERE image = ? LIMIT 1");
        $stmt->execute([$image]);
        echo json_encode(['success' => true]);
        break;
    
    // ===== КОНТЕНТ САЙТА =====
    
    case 'getContent':
        $stmt = $pdo->query("SELECT key_name, value FROM site_content");
        $rows = $stmt->fetchAll();
        $content = [];
        foreach ($rows as $row) {
            $content[$row['key_name']] = $row['value'];
        }
        echo json_encode($content);
        break;
        
    case 'saveContent':
        if ($method !== 'POST') break;
        
        try {
            foreach ($input as $key => $value) {
                $stmt = $pdo->prepare("INSERT INTO site_content (key_name, value) VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE value = ?");
                $stmt->execute([$key, $value, $value]);
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
    
    // ===== АВТОРИЗАЦИЯ =====
    
    case 'login':
        if ($method !== 'POST') break;
        $password = $input['password'] ?? '';
        
        // Проверяем пароль из базы или из конфига
        $stmt = $pdo->prepare("SELECT value FROM site_content WHERE key_name = 'adminPassword'");
        $stmt->execute();
        $row = $stmt->fetch();
        $storedPassword = $row ? $row['value'] : ADMIN_PASSWORD;
        
        if ($password === $storedPassword) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Неверный пароль']);
        }
        break;
        
    case 'changePassword':
        if ($method !== 'POST') break;
        $currentPassword = $input['currentPassword'] ?? '';
        $newPassword = $input['newPassword'] ?? '';
        
        // Проверяем текущий пароль
        $stmt = $pdo->prepare("SELECT value FROM site_content WHERE key_name = 'adminPassword'");
        $stmt->execute();
        $row = $stmt->fetch();
        $storedPassword = $row ? $row['value'] : ADMIN_PASSWORD;
        
        if ($currentPassword !== $storedPassword) {
            echo json_encode(['success' => false, 'error' => 'Неверный текущий пароль']);
            break;
        }
        
        $stmt = $pdo->prepare("INSERT INTO site_content (key_name, value) VALUES ('adminPassword', ?) 
            ON DUPLICATE KEY UPDATE value = ?");
        $stmt->execute([$newPassword, $newPassword]);
        
        echo json_encode(['success' => true]);
        break;
    
    default:
        echo json_encode(['error' => 'Unknown action: ' . $action]);
}
?>

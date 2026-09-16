<?php

declare(strict_types=1);

function run_migrations(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    add_column_if_missing($pdo, 'users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'users', 'full_name', 'VARCHAR(120) NULL');
    add_column_if_missing($pdo, 'users', 'email', 'VARCHAR(180) NULL');
    add_column_if_missing($pdo, 'users', 'role', "VARCHAR(40) NOT NULL DEFAULT 'committee'");
    add_column_if_missing($pdo, 'users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'users', 'last_login_at', 'DATETIME NULL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_permissions (
            user_id INT NOT NULL,
            permission VARCHAR(80) NOT NULL,
            CONSTRAINT pk_user_permissions PRIMARY KEY (user_id, permission)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS uniforms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_name VARCHAR(100) NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            size VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    add_column_if_missing($pdo, 'uniforms', 'student_code', 'VARCHAR(50) NULL');
    add_column_if_missing($pdo, 'uniforms', 'grade', "VARCHAR(10) NOT NULL DEFAULT '12'");
    add_column_if_missing($pdo, 'uniforms', 'section', 'VARCHAR(20) NULL');
    add_column_if_missing($pdo, 'uniforms', 'quantity', 'INT NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'uniforms', 'status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
    add_column_if_missing($pdo, 'uniforms', 'payment_status', "VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
    add_column_if_missing($pdo, 'uniforms', 'notes', 'TEXT NULL');
    add_column_if_missing($pdo, 'uniforms', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
    migrate_student_profile($pdo);
    migrate_profile_requests($pdo);
    migrate_payments($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            event_date DATE NULL,
            event_time VARCHAR(40) NULL,
            location VARCHAR(180) NULL,
            cover_image VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'upcoming',
            published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            announced_on DATE NULL,
            image VARCHAR(255) NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gallery (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            caption TEXT NULL,
            image_path VARCHAR(255) NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'class',
            taken_on DATE NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS committee_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            position VARCHAR(120) NOT NULL,
            bio TEXT NULL,
            photo VARCHAR(255) NULL,
            display_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS achievements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            achieved_on DATE NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'class',
            image VARCHAR(255) NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor VARCHAR(50) NOT NULL,
            action VARCHAR(80) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id INT NULL,
            detail VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    add_column_if_missing($pdo, 'events', 'category', "VARCHAR(40) NOT NULL DEFAULT 'class'");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS timeline_milestones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            occurred_on DATE NULL,
            stage VARCHAR(40) NOT NULL DEFAULT 'milestone',
            highlight TINYINT(1) NOT NULL DEFAULT 0,
            display_order INT NOT NULL DEFAULT 0,
            published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS memories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            body TEXT NOT NULL,
            attribution VARCHAR(120) NULL,
            context VARCHAR(180) NULL,
            memory_on DATE NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS spotlights (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_name VARCHAR(120) NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'student_of_month',
            title VARCHAR(180) NULL,
            description TEXT NULL,
            photo VARCHAR(255) NULL,
            featured_on DATE NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS class_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            author_name VARCHAR(120) NOT NULL,
            author_role VARCHAR(40) NOT NULL DEFAULT 'class',
            body TEXT NOT NULL,
            published TINYINT(1) NOT NULL DEFAULT 0,
            display_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_key = setting_key'
    );
    foreach (setting_defaults() as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (username, password, must_change_password, full_name, role, is_active) VALUES (?, ?, 1, ?, ?, 1)')
            ->execute(['admin', $hash, 'Administrator', 'super_admin']);
    }

    if (column_exists($pdo, 'users', 'role')) {
        $superCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
        if ($superCount === 0) {
            $pdo->exec("UPDATE users SET role = 'super_admin', is_active = 1 ORDER BY id ASC LIMIT 1");
        }
        $pdo->exec("UPDATE users SET full_name = username WHERE full_name IS NULL OR full_name = ''");
    }

    $committeeCount = (int) $pdo->query('SELECT COUNT(*) FROM committee_members')->fetchColumn();
    if ($committeeCount === 0) {
        $seed = $pdo->prepare('INSERT INTO committee_members (name, position, bio, display_order) VALUES (?, ?, ?, ?)');
        $seed->execute(['Alex Johnson', 'Batch President', 'Placeholder officer profile — replace with the current class president.', 1]);
        $seed->execute(['Maria Rodriguez', 'VP for Communications', 'Placeholder officer profile — replace with the current communications officer.', 2]);
        $seed->execute(['David Kim', 'VP for Finance', 'Placeholder officer profile — replace with the current finance officer.', 3]);
        $seed->execute(['Sophia Williams', 'VP for Activities', 'Placeholder officer profile — replace with the current activities officer.', 4]);
    }

    migrate_auth_portal($pdo);
}

function migrate_auth_portal(PDO $pdo): void
{
    if (!function_exists('permission_catalog')) {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        if (!function_exists('current_user')) {
            require_once $root . '/includes/auth.php';
        }
        require_once $root . '/includes/permissions.php';
    }

    add_column_if_missing($pdo, 'users', 'student_id', 'INT NULL');
    add_column_if_missing($pdo, 'audit_logs', 'user_id', 'INT NULL');
    add_column_if_missing($pdo, 'audit_logs', 'ip', 'VARCHAR(45) NULL');

    add_index_if_missing(
        $pdo,
        'users',
        'uq_users_student_id',
        'CREATE UNIQUE INDEX uq_users_student_id ON users (student_id)'
    );
    add_index_if_missing(
        $pdo,
        'users',
        'idx_users_role',
        'CREATE INDEX idx_users_role ON users (role)'
    );
    add_index_if_missing(
        $pdo,
        'audit_logs',
        'idx_audit_user',
        'CREATE INDEX idx_audit_user ON audit_logs (user_id)'
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            description VARCHAR(255) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(180) NULL,
            group_name VARCHAR(80) NOT NULL DEFAULT 'General'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY (role_id, permission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $roleStmt = $pdo->prepare(
        'INSERT INTO roles (slug, name, description, is_system) VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_system = 1'
    );
    $roleStmt->execute(['super_admin', 'Super Admin', 'Unrestricted access to the class portal.']);
    $roleStmt->execute(['committee', 'Committee Member', 'Access is limited to permissions assigned by a Super Admin.']);
    $roleStmt->execute(['student', 'Student', 'Student portal access linked to one class record.']);

    if (function_exists('permission_catalog')) {
        $permStmt = $pdo->prepare(
            'INSERT INTO permissions (name, description, group_name) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), group_name = VALUES(group_name)'
        );
        foreach (permission_catalog() as $group => $perms) {
            foreach ($perms as $name => $description) {
                $permStmt->execute([$name, $description, $group]);
            }
        }
        if (function_exists('student_permission_catalog')) {
            foreach (student_permission_catalog() as $group => $perms) {
                foreach ($perms as $name => $description) {
                    $permStmt->execute([$name, $description, $group]);
                }
            }
        }
        sync_system_role_permissions($pdo);
    }

    try {
        $pdo->exec("DELETE FROM user_permissions WHERE permission IN ('settings.manage','users.manage','activity.view','roles.manage')");
    } catch (Throwable $e) {
        app_log('permission cleanup: ' . $e->getMessage());
    }

    $committeeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'committee'")->fetchColumn();
    if ($committeeUsers === 0) {
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password, must_change_password, full_name, role, is_active) VALUES (?, ?, 1, ?, ?, 1)'
        );
        for ($i = 1; $i <= 5; $i++) {
            $username = 'committee' . $i;
            $exists = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $exists->execute([$username]);
            if ($exists->fetch()) {
                continue;
            }
            $insert->execute([
                $username,
                password_hash(generate_temp_password(16), PASSWORD_DEFAULT),
                'Committee Member ' . $i,
                'committee',
            ]);
        }
    }
}

function sync_system_role_permissions(PDO $pdo): void
{
    if (!function_exists('all_permissions') || !function_exists('student_permissions')) {
        return;
    }
    $roleIds = [];
    foreach ($pdo->query('SELECT id, slug FROM roles')->fetchAll() as $row) {
        $roleIds[(string) $row['slug']] = (int) $row['id'];
    }
    $permIds = [];
    foreach ($pdo->query('SELECT id, name FROM permissions')->fetchAll() as $row) {
        $permIds[(string) $row['name']] = (int) $row['id'];
    }
    $map = [
        'super_admin' => all_permissions(),
        'student' => student_permissions(),
        'committee' => ['dashboard.view'],
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
    foreach ($map as $slug => $names) {
        $roleId = $roleIds[$slug] ?? 0;
        if ($roleId <= 0) {
            continue;
        }
        $existing = (int) $pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . $roleId)->fetchColumn();
        $locked = in_array($slug, ['super_admin', 'student'], true);
        if ($existing > 0 && !$locked) {
            continue;
        }
        if ($locked) {
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
        }
        foreach ($names as $name) {
            if (!isset($permIds[$name])) {
                continue;
            }
            $insert->execute([$roleId, $permIds[$name]]);
        }
    }
}

function migrate_student_profile(PDO $pdo): void
{
    if (!table_exists($pdo, 'uniforms')) {
        return;
    }
    add_column_if_missing($pdo, 'uniforms', 'mother_name', 'VARCHAR(100) NULL');
    add_column_if_missing($pdo, 'uniforms', 'mother_phone', 'VARCHAR(30) NULL');
    add_column_if_missing($pdo, 'uniforms', 'father_name', 'VARCHAR(100) NULL');
    add_column_if_missing($pdo, 'uniforms', 'father_phone', 'VARCHAR(30) NULL');
    add_column_if_missing($pdo, 'uniforms', 'photo', 'VARCHAR(255) NULL');
}

function migrate_profile_requests(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS profile_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            user_id INT NOT NULL,
            phone_number VARCHAR(30) NULL,
            mother_name VARCHAR(100) NULL,
            mother_phone VARCHAR(30) NULL,
            father_name VARCHAR(100) NULL,
            father_phone VARCHAR(30) NULL,
            photo VARCHAR(255) NULL,
            remove_photo TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_profile_requests_student (student_id),
            INDEX idx_profile_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function migrate_payments(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            method VARCHAR(40) NOT NULL DEFAULT 'bank',
            label VARCHAR(120) NOT NULL,
            account_name VARCHAR(120) NULL,
            account_number VARCHAR(80) NOT NULL,
            notes VARCHAR(255) NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment_accounts_active (is_active, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payment_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            user_id INT NOT NULL,
            account_id INT NULL,
            account_label VARCHAR(180) NULL,
            amount DECIMAL(12,2) NULL,
            reference VARCHAR(80) NULL,
            reference_key VARCHAR(80) NULL,
            receipt VARCHAR(255) NULL,
            receipt_hash CHAR(64) NULL,
            student_note VARCHAR(255) NULL,
            claimed_status VARCHAR(20) NOT NULL DEFAULT 'paid',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            note VARCHAR(255) NULL,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_payment_requests_student (student_id),
            INDEX idx_payment_requests_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    add_column_if_missing($pdo, 'payment_requests', 'reference_key', 'VARCHAR(80) NULL');
    add_column_if_missing($pdo, 'payment_requests', 'receipt_hash', 'CHAR(64) NULL');
    add_index_if_missing(
        $pdo,
        'payment_requests',
        'idx_payment_requests_reference_key',
        'ALTER TABLE payment_requests ADD INDEX idx_payment_requests_reference_key (reference_key)'
    );
    add_index_if_missing(
        $pdo,
        'payment_requests',
        'idx_payment_requests_receipt_hash',
        'ALTER TABLE payment_requests ADD INDEX idx_payment_requests_receipt_hash (receipt_hash)'
    );
    backfill_payment_proof_keys($pdo);
}

function backfill_payment_proof_keys(PDO $pdo): void
{
    if (!column_exists($pdo, 'payment_requests', 'reference_key') || !column_exists($pdo, 'payment_requests', 'receipt_hash')) {
        return;
    }
    $rows = $pdo->query(
        "SELECT id, reference, receipt, receipt_hash
         FROM payment_requests
         WHERE (reference_key IS NULL OR reference_key = '')
            OR ((receipt_hash IS NULL OR receipt_hash = '') AND receipt IS NOT NULL AND receipt != '')"
    )->fetchAll();
    if (!$rows) {
        return;
    }
    $update = $pdo->prepare('UPDATE payment_requests SET reference_key = ?, receipt_hash = ? WHERE id = ?');
    foreach ($rows as $row) {
        $key = strtoupper(trim((string) ($row['reference'] ?? '')));
        $key = preg_replace('/[\s\-_.]+/', '', $key) ?? '';
        $key = $key !== '' ? substr($key, 0, 80) : null;
        $hash = trim((string) ($row['receipt_hash'] ?? ''));
        if ($hash === '') {
            $relative = str_replace('\\', '/', (string) ($row['receipt'] ?? ''));
            if ($relative !== '' && !str_contains($relative, '..') && str_starts_with($relative, 'uploads/')) {
                $full = APP_ROOT . '/' . $relative;
                if (is_file($full)) {
                    $computed = hash_file('sha256', $full);
                    $hash = is_string($computed) && $computed !== '' ? $computed : '';
                }
            }
        }
        $update->execute([$key !== '' ? $key : null, $hash !== '' ? $hash : null, (int) $row['id']]);
    }
}

function migrate_notifications(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            audience VARCHAR(20) NOT NULL DEFAULT 'user',
            user_id INT NULL,
            actor_id INT NULL,
            type VARCHAR(60) NOT NULL,
            title VARCHAR(180) NOT NULL,
            body VARCHAR(400) NULL,
            url VARCHAR(255) NULL,
            icon VARCHAR(40) NULL,
            target_type VARCHAR(40) NULL,
            target_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_audience (audience, created_at),
            INDEX idx_notifications_user (user_id, created_at),
            INDEX idx_notifications_target (type, target_type, target_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_reads (
            notification_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at DATETIME NOT NULL,
            PRIMARY KEY (notification_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function migrate_interactions(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS polls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            choice_type VARCHAR(20) NOT NULL DEFAULT 'single',
            anonymous TINYINT(1) NOT NULL DEFAULT 0,
            allow_change TINYINT(1) NOT NULL DEFAULT 1,
            show_results VARCHAR(20) NOT NULL DEFAULT 'immediate',
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            featured TINYINT(1) NOT NULL DEFAULT 0,
            is_decision TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_polls_status (status, featured, ends_at),
            INDEX idx_polls_decision (is_decision, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS poll_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poll_id INT NOT NULL,
            label VARCHAR(180) NOT NULL,
            display_order INT NOT NULL DEFAULT 0,
            INDEX idx_poll_options_poll (poll_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS poll_votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            poll_id INT NOT NULL,
            student_id INT NOT NULL,
            user_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_poll_student (poll_id, student_id),
            INDEX idx_poll_votes_poll (poll_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS poll_vote_choices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vote_id INT NOT NULL,
            option_id INT NOT NULL,
            UNIQUE KEY uq_vote_option (vote_id, option_id),
            INDEX idx_vote_choices_option (option_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            body TEXT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'open',
            allow_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            allow_change TINYINT(1) NOT NULL DEFAULT 1,
            moderate TINYINT(1) NOT NULL DEFAULT 0,
            featured TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            official_answer TEXT NULL,
            pin_response_id INT NULL,
            created_by INT NULL,
            created_student_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_questions_status (status, kind, featured)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_options (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question_id INT NOT NULL,
            label VARCHAR(180) NOT NULL,
            display_order INT NOT NULL DEFAULT 0,
            INDEX idx_question_options_q (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question_id INT NOT NULL,
            student_id INT NOT NULL,
            user_id INT NOT NULL,
            body TEXT NULL,
            is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'visible',
            useful TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_question_student (question_id, student_id),
            INDEX idx_question_responses_q (question_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS question_response_choices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            response_id INT NOT NULL,
            option_id INT NOT NULL,
            UNIQUE KEY uq_response_option (response_id, option_id),
            INDEX idx_qrc_option (option_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    add_column_if_missing($pdo, 'polls', 'is_decision', 'TINYINT(1) NOT NULL DEFAULT 0');
    add_index_if_missing($pdo, 'polls', 'idx_polls_decision', 'CREATE INDEX idx_polls_decision ON polls (is_decision, status)');
}

function migrate_class_brand(PDO $pdo): void
{
    if (!table_exists($pdo, 'settings')) {
        return;
    }
    $pdo->exec("UPDATE settings SET setting_value = REPLACE(setting_value, 'LUCHADORE', 'LUCHADOR')");
    $pdo->exec("UPDATE settings SET setting_value = REPLACE(setting_value, 'Luchadore', 'Luchador')");
}

function ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo = db();
        $needsUpgrade = !table_exists($pdo, 'settings')
            || !table_exists($pdo, 'events')
            || !table_exists($pdo, 'audit_logs')
            || !table_exists($pdo, 'timeline_milestones')
            || !table_exists($pdo, 'memories')
            || !table_exists($pdo, 'spotlights')
            || !table_exists($pdo, 'class_messages')
            || !column_exists($pdo, 'uniforms', 'status')
            || !table_exists($pdo, 'user_permissions')
            || !column_exists($pdo, 'users', 'role')
            || !column_exists($pdo, 'users', 'student_id')
            || !table_exists($pdo, 'roles')
            || !column_exists($pdo, 'events', 'category');
        if ($needsUpgrade) {
            run_migrations($pdo);
        } else {
            migrate_auth_portal($pdo);
        }
        migrate_student_profile($pdo);
        migrate_profile_requests($pdo);
        migrate_payments($pdo);
        migrate_notifications($pdo);
        migrate_interactions($pdo);
        migrate_class_brand($pdo);
    } catch (Throwable $e) {
        app_log('ensure_schema: ' . $e->getMessage());
    }
    $done = true;
}

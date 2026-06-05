<?php

function get_setting($conn, $key, $default = '')
{
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['setting_value'] ?? $default;
    }
    return $default;
}

function set_setting($conn, $key, $value)
{
    $stmt = $conn->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->bind_param('ss', $key, $value);
    return $stmt->execute();
}

function get_all_settings($conn)
{
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

function is_smtp_configured($settings)
{
    return !empty($settings['smtp_host'])
        && !empty($settings['smtp_username'])
        && !empty($settings['smtp_from_email']);
}

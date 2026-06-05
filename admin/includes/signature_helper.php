<?php

function payslip_uploads_dir()
{
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function payslip_signature_relative_path($settings)
{
    $path = trim($settings['payslip_signature'] ?? '');
    return $path !== '' ? $path : null;
}

function payslip_signature_absolute_path($settings)
{
    $rel = payslip_signature_relative_path($settings);
    if ($rel === null) {
        return null;
    }
    $full = dirname(__DIR__) . '/' . str_replace(['../', '..\\'], '', $rel);
    return is_file($full) ? $full : null;
}

function payslip_signature_url($settings)
{
    $rel = payslip_signature_relative_path($settings);
    if ($rel === null) {
        return null;
    }
    $full = dirname(__DIR__) . '/' . $rel;
    if (!is_file($full)) {
        return null;
    }
    return 'uploads/' . basename($rel) . '?v=' . filemtime($full);
}

function save_payslip_signature_upload(array $file)
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed. Please try again.'];
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        return ['success' => false, 'message' => 'Signature image must be under 2MB.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return ['success' => false, 'message' => 'Only PNG, JPG or GIF images are allowed.'];
    }

    $ext = $allowed[$mime];
    payslip_uploads_dir();

    foreach (glob(dirname(__DIR__) . '/uploads/payslip_signature.*') as $old) {
        @unlink($old);
    }

    $relative = 'uploads/payslip_signature.' . $ext;
    $dest = dirname(__DIR__) . '/' . $relative;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success' => false, 'message' => 'Could not save the uploaded file.'];
    }

    return ['success' => true, 'message' => 'Signature uploaded.', 'path' => $relative];
}

function remove_payslip_signature()
{
    foreach (glob(dirname(__DIR__) . '/uploads/payslip_signature.*') as $file) {
        @unlink($file);
    }
    return true;
}

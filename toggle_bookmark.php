<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

// student_id is VARCHAR in the new schema
$student_id = (string)($_SESSION['student_id']);

// Accept legacy 'paper_id' or new 'book_id'
$book_id = 0;
if (isset($_POST['book_id'])) {
    $book_id = (int)$_POST['book_id'];
} elseif (isset($_POST['paper_id'])) {
    $book_id = (int)$_POST['paper_id'];
}
if ($book_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid paper ID']);
    exit;
}

try {
    // Check current state
    $stmt = $conn->prepare('SELECT id FROM cap_bookmarks WHERE student_id = ? AND book_id = ?');
    $stmt->execute([$student_id, $book_id]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        // Remove bookmark
        $del = $conn->prepare('DELETE FROM cap_bookmarks WHERE id = ?');
        $del->execute([$existingId]);
        echo json_encode(['success' => true, 'bookmarked' => false]);
        exit;
    } else {
        // Add bookmark
        $ins = $conn->prepare('INSERT INTO cap_bookmarks (student_id, book_id) VALUES (?, ?)');
        $ins->execute([$student_id, $book_id]);
        echo json_encode(['success' => true, 'bookmarked' => true]);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

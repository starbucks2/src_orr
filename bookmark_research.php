<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Only students can bookmark.']);
    exit;
}

if (!isset($_POST['research_id']) || !is_numeric($_POST['research_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid research ID.']);
    exit;
}


$student_id = (string)($_SESSION['student_id']);
$book_id = (int)$_POST['research_id'];

try {
    // Check if already bookmarked
    $stmt = $conn->prepare("SELECT id FROM cap_bookmarks WHERE student_id = ? AND book_id = ?");
    $stmt->execute([$student_id, $book_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Already bookmarked.']);
        exit;
    }

    // Insert bookmark
    $stmt = $conn->prepare("INSERT INTO cap_bookmarks (student_id, book_id) VALUES (?, ?)");
    $stmt->execute([$student_id, $book_id]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}

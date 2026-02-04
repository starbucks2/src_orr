<?php

/**
 * Cleanup script to remove duplicate research titles from books table
 * Keeps only the latest entry (highest book_id) for each duplicate title
 * Run this once to clean existing duplicates before adding unique constraint
 */

require_once __DIR__ . '/db.php';

echo "<!DOCTYPE html><html><head><title>Cleanup Duplicates</title></head><body>";
echo "<h1>Removing Duplicate Research Titles</h1>";
echo "<pre>";

try {
    // Find all duplicate titles (case-insensitive, approved only)
    $find_dupes = $conn->prepare("
        SELECT LOWER(TRIM(title)) as normalized_title, COUNT(*) as count
        FROM cap_books
        WHERE status = 1
        GROUP BY LOWER(TRIM(title))
        HAVING COUNT(*) > 1
    ");
    $find_dupes->execute();
    $duplicates = $find_dupes->fetchAll(PDO::FETCH_ASSOC);

    if (empty($duplicates)) {
        echo "No duplicate titles found.\n";
    } else {
        echo "Found " . count($duplicates) . " duplicate title(s):\n\n";

        $total_removed = 0;

        foreach ($duplicates as $dup) {
            $normalized = $dup['normalized_title'];
            $count = $dup['count'];

            echo "Title: '{$normalized}' ({$count} copies)\n";

            // Get all book_ids with this title, ordered by book_id DESC (newest first)
            $get_ids = $conn->prepare("
                SELECT book_id, title, submission_date
                FROM cap_books
                WHERE LOWER(TRIM(title)) = ?
                ORDER BY book_id DESC
            ");
            $get_ids->execute([$normalized]);
            $entries = $get_ids->fetchAll(PDO::FETCH_ASSOC);

            // Keep the first one (highest book_id = newest), delete the rest
            $keep_id = $entries[0]['book_id'];
            echo "  Keeping book_id {$keep_id} (submitted: {$entries[0]['submission_date']})\n";

            for ($i = 1; $i < count($entries); $i++) {
                $delete_id = $entries[$i]['book_id'];
                echo "  Deleting book_id {$delete_id} (submitted: {$entries[$i]['submission_date']})\n";

                // Delete from cap_books (cascades to cap_bookmarks if any)
                $del_stmt = $conn->prepare("DELETE FROM cap_books WHERE book_id = ?");
                $del_stmt->execute([$delete_id]);
                $total_removed++;
            }
            echo "\n";
        }

        echo "Total duplicates removed: {$total_removed}\n";
    }

    echo "\n✓ Cleanup complete!\n";
    echo "\nNext step: Run the ALTER TABLE command to add unique constraint on title.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p><a href='admin_dashboard.php'>Back to Admin Dashboard</a></p>";
echo "</body></html>";

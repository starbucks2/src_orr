<?php
require 'db.php';
try {
    $conn->exec("DROP VIEW IF EXISTS `research_submission`");
    $sql = "CREATE VIEW `research_submission` AS
            SELECT
              b.`book_id` AS `id`,
              b.`title` AS `title`,
              b.`abstract` AS `abstract`,
              b.`keywords` AS `keywords`,
              b.`authors` AS `author`,
              NULL AS `members`,
              b.`department` AS `department`,
              b.`course_strand` AS `course_strand`,
              b.`image` AS `image`,
              b.`document` AS `document`,
              b.`views` AS `views`,
              b.`submission_date` AS `submission_date`,
              b.`student_id` AS `student_id`,
              b.`adviser_id` AS `adviser_id`,
              b.`status` AS `status`,
              b.`year` AS `year`
            FROM `cap_books` b";
    $conn->exec($sql);
    echo "View research_submission fixed successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

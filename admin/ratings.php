<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireLogin();

$pageTitle = 'Manage Ratings';
include '../includes/header.php';

$ratings = $conn->query("SELECT r.*, c.company_name FROM ratings r JOIN customers c ON r.company_id = c.id ORDER BY r.created_at DESC");
?>

<div class="admin-content">
    <h1>Manage Ratings</h1>
    <a href="index.php">Back to Dashboard</a>
    
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Company</th>
                <th>Rating</th>
                <th>Comment</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $ratings->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['id']; ?></td>
                <td><?php echo $row['company_name']; ?></td>
                <td><?php echo $row['rating']; ?> ★</td>
                <td><?php echo $row['comment']; ?></td>
                <td><?php echo $row['created_at']; ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>

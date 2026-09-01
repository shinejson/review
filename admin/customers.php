<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

requireLogin();

$pageTitle = 'Manage Customers';
include '../includes/header.php';

$customers = $conn->query("SELECT * FROM customers ORDER BY company_name");
?>

<div class="admin-content">
    <h1>Manage Customers</h1>
    <a href="index.php">Back to Dashboard</a>
    
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Company Name</th>
                <th>Average Rating</th>
                <th>Total Ratings</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $customers->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['id']; ?></td>
                <td><?php echo $row['company_name']; ?></td>
                <td><?php echo getAverageRating($row['id'], $conn); ?> ★</td>
                <td><?php echo getRatingCount($row['id'], $conn); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
	<?php include("backend/includes/head.php"); // Head for all pages ?>
    <script src="./backend/js/application.js"></script>
</head>
<body>
	<main>
		<div class="sidebar">
			<h3><?php include("backend/includes/serverstatus.php"); // Server & Player Status ?></h3>
		</div>
		<div class="wrapper">
			<?php include("backend/includes/banner.php"); // Banner ?>
			<?php include("backend/includes/navbar.php"); // Navigation Bar ?>
            <?php include("backend/includes/apply.php"); // Application Form ?>
		</div>
	</main>
    <footer>
		<?php include("backend/includes/footer.php"); // Footer ?>
	</footer>
</body>
</html>
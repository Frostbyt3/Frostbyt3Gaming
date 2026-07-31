<!DOCTYPE html>
<html lang="en">
<head>
	<?php include("backend/includes/head.php"); // Head for all pages ?>
</head>
<body>
	<main>
		<div class="sidebar">
			<h3><?php include("backend/includes/serverstatus.php"); // Server & Player Status ?></h3>
		</div>
		<div class="wrapper">
			<?php include("backend/includes/banner.php"); // Banner ?>
			<?php include("backend/includes/navbar.php"); // Navigation Bar ?> 
			<?php include("backend/includes/tutorial.php"); // Tutorial Cards ?>
		</div>
	</main>
    <footer>
		<?php include("backend/includes/footer.php"); // Footer ?>
	</footer>
</body>
</html>
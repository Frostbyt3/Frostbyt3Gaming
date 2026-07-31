<?php
    $wait = 1;
    $pinghost = "192.168.50.160";
    $pingport = 10044;

    if($fp = @fsockopen($pinghost, $pingport, $errCode, $errStr, $wait)) {
        echo "Server Status: <greens><i class='fas fa-circle'></i></greens><br><br>";
        $servername = "192.168.50.160";
        $username = "panel";
        $password = "1721550Abc";
        $dbname = "qbcoreframework_1843d4";


        $conn = new mysqli($servername, $username, $password, $dbname);

        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "SELECT name, firstname, lastname FROM website WHERE online = 1";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            echo "<h3>Online Players:</h3>";
            while($row = $result->fetch_assoc()) {
                echo "<h5><greens>&#x2022;</greens> ". $row["name"]. " [" . $row["firstname"]. " " . $row["lastname"]. "]</h5>";
            }
        } else {
            echo "<h3>Online Players:</h3>";
            echo "<h5>No players are currently online</h5>";
        }

        $conn->close();
    } else {
        echo "Server Status: <reds><i class='fas fa-circle'></i></reds><br><br>";
    }
?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Event listener for 'Load More' button
        document.getElementById('load-more').addEventListener('click', function() {
            const hiddenItems = Array.from(document.querySelectorAll('.news.hidden')); // Get all hidden items
            const itemsToShow = Math.min(3, hiddenItems.length); // Limit to showing 3 items at a time

            // Reveal the next 3 hidden articles
            for (let i = 0; i < itemsToShow; i++) {
                hiddenItems[i].classList.remove('hidden');
            }

            // If no hidden items left, hide the 'Load More' button
            if (document.querySelectorAll('.news.hidden').length === 0) {
                this.style.display = 'none';
            }
        });
    });
</script>
<div class="section-extra">
    <h2>Keep up-to-date on the latest news & events</h2>
    <div class="section-extra-flex">
        <?php 
        $newsArticles = include('articles.php'); // Include news from a separate file

        foreach ($newsArticles as $index => $article) {
            $hiddenClass = $index >= 3 ? 'hidden' : '';
            echo "<div class='news $hiddenClass'>$article</div>";
        }
        ?>
        <br>
    </div>
    <center><button id="load-more">Load More</button></center>
</div>

<!-- <div class="section-extra">
    <h2>Keep up-to-date on the latest news & events</h2>
    <div class="section-extra-flex">
        
        <div class="news">
            <h3>Welcome to Frostbyt3 RP</h3>
            <p>Welcome to Frostbyt3 RP! We're here to have fun, sell drugs, race cars, etc...</p>
            <p>You can connect to the server by clicking the "Connect" button below.</p>
            <br>
            <center><a href="fivem://connect/frostbyt3.games:30120" class="button">Connect</a></center>
            <br>
        </div>

        <div class="news">
            <h3>Server is now public!</h3>
            <p>We've been working very hard to bring you an immersive and semi-realistic type of gameplay.</p>
            <p>We think we're ready to go live!</p>
        </div>

        <div class="news">
            <h3>It‘s December in Los Santos!</h3>
            <p>Now that it’s December in Los Santos, you’ll notice that it has been snowing. Be careful!</p>
        </div>

    </div>
</div> -->
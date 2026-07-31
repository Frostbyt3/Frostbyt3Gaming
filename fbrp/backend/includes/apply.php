<section class="section-extra-apply">
    <div class="applicationcontainer">
    <h2>Nickname & Discord Name: *</h2>
    <p><input type="text" name="username" required></p>
    </br>
    <h2>Rank: *</h2>
    <p>
        <select name="subject" onclick="selectRang(this.value)" require>
            <option value="Developer">Developer</option>
            <option value="Moderator">Moderator</option>
            <option value="Concept">Concept Artist</option>
            <option value="Other">Other</option>
        </select>
    </p>
    </br>
    <h2>E-Mail Address: *</h2>
    <p><input type="email" name="email" required></p>
    </br>
    <h2>About You: *</h2>
    <p><textarea name="body" required></textarea></p>
    </br>
    <p>
        <button type="submit" name="submit">Submit</button>
    </p>
</div>
    <div class="dev">
        <h2>Which scripting languages are you proficient in?</h2>
        <p><input type="text" name="dev-languages"></p>
        </br>
        <h2>Which language would you prefer?</h2>
        <p><input type="text" name="dev-preference"></p>
        </br>
        <h2>How many years of experience do you have?</h2>
        <p><input type="text" name="dev-experience"></p>
        </br>
        <h2>How would you describe your online time?</h2>
        <p><input type="text" name="dev-time"></p>
    </div>
    <div class="mod" style="display: none;">
        <h2>What does a moderator do?</h2>
        <p><input type="text" name="mod-whatdoes"></p >
        </br>
        <h2>Where/when does moderating begin and end?</h2>
        <p><input type="text" name="mod-start"></p >
        </br>
        <h2>What does being a moderator mean to you?</h2>
        <p><input type="text" name="mod-meaning"></p >
        </br>
        <h2>How much time do you plan on spending moderating? (Approx.)</h2>
        <p><input type="text" name="mod-time"></p >
        </br>
        <h2>How would you describe your online time?</h2>
        <p><input type="text" name="mod-time"></p >
    </div>
</section>

<script>
    function selectRang(value) {
    console.log(value);
    if (value == "Developer") {
        document.getElementsByClassName('dev')[0].style.display = "block";
    } else {
        document.getElementsByClassName('dev')[0].style.display = "none";
    }

    if (value == "Moderator") {
        document.getElementsByClassName('mod')[0].style.display = "block";
    } else {
        document.getElementsByClassName('mod')[0].style.display = "none";
    }

    if (value == "Concept") {
        document.getElementsByClassName('concept')[0].style.display = "block";
    } else {
        document.getElementsByClassName('concept')[0].style.display = "none";
    }

    if (value == "others") {
        document.getElementsByClassName('other')[0].style.display = "block";
    } else {
        document.getElementsByClassName('other')[0].style.display = "none";
    }
}
</script>
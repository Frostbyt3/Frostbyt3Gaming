function selectRang(value) {
    console.log(value);
    if (value == "Developer") {
        document.getElementsByClassName('dev')[0].style.display = "block";
    } else {
        document.getElementsByClassName('dev')[0].style.display = "none";
    }

    if (value == "Moderator") {
        document.getElementsByClassName('sup')[0].style.display = "block";
    } else {
        document.getElementsByClassName('sup')[0].style.display = "none";
    }

    if (value == "Concept") {
        document.getElementsByClassName('kon')[0].style.display = "block";
    } else {
        document.getElementsByClassName('kon')[0].style.display = "none";
    }

    if (value == "others") {
        document.getElementsByClassName('son')[0].style.display = "block";
    } else {
        document.getElementsByClassName('son')[0].style.display = "none";
    }
}
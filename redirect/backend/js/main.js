let seconds = 5;
const countdown = document.getElementById("countdown");
setInterval(() => {
    seconds--;
    if (seconds > 0) {
        countdown.textContent = seconds;
    }
}, 1000);

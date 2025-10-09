document.addEventListener('DOMContentLoaded', function() {
    const body = document.body;

    // Load saved preferences
    ['dark-mode', 'large-font', 'dyslexia-font'].forEach(pref => {
        if (localStorage.getItem(pref) === 'true') body.classList.add(pref);
    });

    document.getElementById('toggle-darkmode').addEventListener('click', () => {
        body.classList.toggle('dark-mode');
        localStorage.setItem('dark-mode', body.classList.contains('dark-mode'));
    });

    document.getElementById('toggle-fontsize').addEventListener('click', () => {
        body.classList.toggle('large-font');
        localStorage.setItem('large-font', body.classList.contains('large-font'));
    });

    document.getElementById('toggle-dyslexia').addEventListener('click', () => {
        body.classList.toggle('dyslexia-font');
        localStorage.setItem('dyslexia-font', body.classList.contains('dyslexia-font'));
    });
});

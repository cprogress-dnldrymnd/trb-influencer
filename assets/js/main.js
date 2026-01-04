function toggleNiches(containerId, btn) {
    var container = document.getElementById(containerId);
    var hiddenTerms = container.querySelectorAll('.term-hidden');

    // Show all hidden terms
    hiddenTerms.forEach(function (term) {
        term.style.display = 'inline-block'; // Or 'inline' depending on your styling
    });

    // Hide the plus button
    btn.style.display = 'none';
}
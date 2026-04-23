// MODALS
function openModal(modalId) {
    document.getElementById(modalId).style.display = "block";
}
function closeModal(modalId) {
    document.getElementById(modalId).style.display = "none";
}
function openDetails(button) {
    document.getElementById("modalTitle").innerText = button.dataset.title;
    document.getElementById("modalCategory").innerText = button.dataset.category;
    document.getElementById("modalPriority").innerText = button.dataset.priority;
    document.getElementById("modalStatus").innerText = button.dataset.status;
    document.getElementById("modalSubmitted").innerText = button.dataset.submitted;
    document.getElementById("modalDescription").innerText = button.dataset.description;

    const attachment = button.dataset.attachment;
    const link = document.getElementById("modalAttachment");
    if (attachment) { link.href = attachment; link.style.display = "inline"; }
    else { link.style.display = "none"; }

    openModal("detailsModal");
}
function closeDetails() { closeModal("detailsModal"); }

// CLICK OUTSIDE HANDLER
window.addEventListener('click', function(e) {
    // Close modals
    document.querySelectorAll(".modal").forEach(modal => {
        if(e.target === modal) modal.style.display = "none";
    });

    // Close profile dropdown
    const profile = document.querySelector('.profile');
    const dropdown = document.getElementById('profileDropdown');
    const arrow = document.querySelector('.profile-arrow');
    if(!profile.contains(e.target)) {
        dropdown.classList.remove('show');
        arrow.classList.remove('open');
    }
});

// PROFILE TOGGLE
document.querySelector('.profile').addEventListener('click', function(e){
    e.stopPropagation(); // Prevent window click from immediately closing dropdown
    const dropdown = document.getElementById('profileDropdown');
    const arrow = document.querySelector('.profile-arrow');
    dropdown.classList.toggle('show');
    arrow.classList.toggle('open');
});

// LOGOUT
document.addEventListener('DOMContentLoaded', function(){
    const logoutBtn = document.querySelector('.profile-dropdown a[href="logout.php"]');
    if(logoutBtn){
        logoutBtn.addEventListener('click', function(e){
            e.preventDefault();
            window.location.href = 'logout.php';
        });
    }
});

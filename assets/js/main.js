// Rating star functionality
document.addEventListener('DOMContentLoaded', function() {
    const ratingInputs = document.querySelectorAll('.rating-stars input');
    
    ratingInputs.forEach(input => {
        input.addEventListener('change', function() {
            const labels = document.querySelectorAll('.rating-stars label');
            const value = this.value;
            
            labels.forEach((label, index) => {
                if (index < value) {
                    label.style.color = '#ffc107';
                } else {
                    label.style.color = '#ddd';
                }
            });
        });
    });
});

// Form validation
function validateRatingForm() {
    const rating = document.querySelector('input[name="rating"]:checked');
    
    if (!rating) {
        alert('Please select a rating');
        return false;
    }
    
    return true;
}

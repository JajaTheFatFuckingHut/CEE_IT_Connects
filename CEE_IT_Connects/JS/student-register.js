document.addEventListener('DOMContentLoaded', () => {
    
    // --- LOADING SCREEN ---
    window.addEventListener('load', () => {
        const loader = document.getElementById('loading-screen');
        if(loader){
            loader.style.opacity = '0';
            setTimeout(() => loader.style.display = 'none', 500);
        }
    });

    // --- POPULATE SECTION DROPDOWN (1 to 15) ---
    const sectionSelect = document.getElementById('sectionSelect');
    if (sectionSelect) {
        for (let i = 1; i <= 15; i++) {
            const option = document.createElement('option');
            option.value = i;
            option.textContent = i; // Shows just the number
            sectionSelect.appendChild(option);
        }
    }

    // --- FILE UPLOAD LABEL UPDATE ---
    const fileInput = document.getElementById('cor-upload');
    const fileLabelText = document.getElementById('file-label-text');

    if(fileInput && fileLabelText) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files.length > 0) {
                fileLabelText.textContent = this.files[0].name; // Show filename
                fileLabelText.style.color = "#333";
            } else {
                fileLabelText.textContent = "Upload Certificate of Registration"; // Reset
            }
        });
    }

    function checkPassword(password){
        const regex = /^(?=.*[a-z])(?=.*[A-Z]).{8,16}$/;
        return regex.test(password);
    }

    // const passwordInput = document.getElementById('password').value;
    // if(!checkPassword(passwordInput)){
    //     alert('Password must be 8-16 characters long and include at least one uppercase and one lowercase letter.');
    // }

    // added for wrong password input
    const passwordInput = document.getElementById('password');
    const passwordError = document.getElementById('password-error');

    if (passwordInput && passwordError) {
        passwordInput.addEventListener('input', function () {
            if (this.value.length === 0) {
                // don't show error on empty field
                passwordError.classList.add('d-none');
                passwordInput.classList.remove('is-invalid');
                return;
            }
            if (!checkPassword(this.value)) {
                passwordError.classList.remove('d-none');
                passwordInput.classList.add('is-invalid');
            } else {
                passwordError.classList.add('d-none');
                passwordInput.classList.remove('is-invalid');
            }
        });

        passwordInput.addEventListener('invalid', function (e) {
            e.preventDefault();
            passwordError.classList.remove('d-none');
            passwordInput.classList.add('is-invalid');
        });
    }
});
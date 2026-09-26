<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CEE IT Connects - Student Register</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../CSS/student-register.css">
</head>

<body>

    <div id="loading-screen">
        <img src="../Sources/CEE IT Connects Logo.png" alt="Logo" class="loading-logo">
    </div>

    <div class="container-fluid login-container">
        <div class="row h-100">
            <div class="col-md-5 p-0 left-panel"></div>

            <div class="col-md-7 right-panel">

                <img src="../Sources/CEE IT Connects Logo.png" alt="CEE IT Logo" class="logo-img">
                <h2 class="login-title">CEE IT CONNECTS</h2>
                <h3 class="login-subtitle">Register</h3>

                <form class="form-wrapper" method="POST" action="student-reg.php" enctype="multipart/form-data">

                    <div class="mb-3">
                        <input type="text" name="full_name" class="form-control"
                            placeholder="Full Name (First Name, Last Name)" required>
                    </div>

                    <div class="mb-3 d-flex gap-2">
                        <select name="year_no" class="form-select" required>
                            <option value="" disabled selected>Year</option>
                            <option value="20">20</option>
                            <option value="21">21</option>
                            <option value="22">22</option>
                            <option value="23">23</option>
                            <option value="24">24</option>
                            <option value="25">25</option>
                        </select>
                        <input type="text" name="id_no" class="form-control" placeholder="Last four digits of ID No."
                            maxlength="4" pattern="\d{4}" required>
                    </div>

                    <div class="mb-3">
                        <select class="form-select" name="program" required>
                            <option value="" disabled selected>Program</option>
                            <option value="Information Technology">Information Technology</option>
                            <option value="Electrical Engineering">Electrical Engineering</option>
                            <option value="Civil Engineering">Civil Engineering</option>
                        </select>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <select name="year_level" class="form-select" required>
                                <option value="" disabled selected>Year Level</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <select name="section" class="form-select" id="sectionSelect" required>
                                <option value="" disabled selected>Section</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <input type="file" name="cor_upload" id="cor-upload" hidden>
                        <label for="cor-upload" class="file-upload-label">
                            <span id="file-label-text">Upload Certificate of Registration</span>
                            <i class="fa fa-download"></i>
                        </label>
                    </div>

                    <div class="mb-3">
                        <input type="password" name="password" pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*(),.?&quot;:{}|&lt;&gt;_\-+=~`\[\];'/\\]).{8,16}$"
                            class="form-control" placeholder="Password" id="password" required>
                        <small class="form-text text-muted">
                            Use 8-16 characters with at least one uppercase, one lowercase, one number, and one special character.
                        </small>
                        <small class="form-text text-danger d-none" id="password-error">
                            Password must be 8-16 characters and include an uppercase letter, a lowercase letter, a number, and a special character.
                        </small>
                    </div>

                    <div class="mb-3">
                        <input type="text" name="contact_number" inputmode="numeric" pattern="[0-9]*" maxlength="10"
                            class="form-control" placeholder="Contact Number">
                    </div>

                    <div class="mb-3">
                        <input type="email" name="email" class="form-control" placeholder="Email Address">
                    </div>

                    <!-- <div class="terms-text">
                        By clicking "Create Account," you agree to the <a href="#" class="terms-link">Terms of Use and Privacy Policy.</a>
                    </div> -->

                    <div class="terms-text">
                        By clicking "Create Account," you agree to the
                        <a href="#" class="terms-link" data-bs-toggle="modal" data-bs-target="#termsModal">Terms of Use and Privacy Policy.</a>
                    </div>

                    <button type="submit" class="btn-login mt-3">
                        Create an account
                    </button>

                    <div class="register-text mt-3">
                        Already have an account? <a href="login-ui.php" class="register-link">Sign in</a>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="../JS/student-register.js"></script>

    <!-- Terms of Use & Privacy Policy Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="termsModalLabel">Terms of Use &amp; Privacy Policy</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <h6>Terms of Use</h6>
            <p>CEE IT Connects is an internship hours-tracking system for PLV CEIT students. By registering, you agree to provide accurate personal and academic information, use the platform only for legitimate internship documentation and communication with your adviser, and refrain from submitting falsified time records or files.</p>
            <p>Your account and any uploaded documents (e.g. Certificate of Registration) may be reviewed by your adviser and department administrators for verification purposes.</p>

            <h6 class="mt-3">Privacy Policy</h6>
            <p>Information you submit — full name, student ID, program, section, contact number, email, and uploaded documents — is used solely to verify enrollment and manage internship tracking within CEE IT Connects.</p>
            <p>Your data will not be shared outside PLV's CEIT department and its internship partners, except as required for academic or administrative processes. You may request corrections to your information through your adviser.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
        </div>
    </div>
    </div>
</body>

</html>
<?php
session_start();
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

include("connection/connection.php");

// Function to generate a unique 6-digit OTP
function generateRandomCode($conn) {
    if (!is_resource($conn) || get_resource_type($conn) !== 'oci8 connection') {
        error_log("Invalid or no database connection in generateRandomCode");
        throw new Exception("Invalid database connection");
    }

    $max_attempts = 10;
    $attempt = 0;

    while ($attempt < $max_attempts) {
        $code = sprintf("%06d", random_int(0, 999999));
        $sql = "SELECT COUNT(*) FROM CUSTOMER WHERE VERIFICATION_CODE = :code";
        $stmt = oci_parse($conn, $sql);
        if (!$stmt) {
            error_log("Failed to parse OTP uniqueness query: " . oci_error($conn)['message']);
            throw new Exception("Failed to prepare OTP uniqueness query: " . oci_error($conn)['message']);
        }

        oci_bind_by_name($stmt, ":code", $code);
        if (!oci_execute($stmt)) {
            $error = oci_error($stmt);
            error_log("OCI Execute Error in generateRandomCode: " . $error['message']);
            oci_free_statement($stmt);
            throw new Exception("Failed to check OTP uniqueness: " . $error['message']);
        }

        $row = oci_fetch_row($stmt);
        $count = $row[0];
        oci_free_statement($stmt);

        if ($count == 0) {
            return $code;
        }

        $attempt++;
    }

    throw new Exception("Unable to generate a unique OTP after $max_attempts attempts");
}

// Function to send OTP via email with retry logic
function sendOTP($email, $user_id, $conn) {
    try {
        $otp = generateRandomCode($conn);
        $sql = "UPDATE CUSTOMER SET VERIFICATION_CODE = :otp WHERE USER_ID = :userid";
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':otp', $otp);
        oci_bind_by_name($stmt, ':userid', $user_id);
        
        if (oci_execute($stmt)) {
            oci_free_statement($stmt);
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'adhikariroshankumar7@gmail.com'; // Replace with your Gmail address
            $mail->Password = 'nbei mnqe qgvp lpcy'; // Replace with your Gmail App Password
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->SMTPDebug = 2; // Enable verbose debug output
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP Debug: $str");
            };
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            $mail->setFrom('your_email@gmail.com', 'CleckFax Traders');
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = 'Your Verification Code';
            $mail->Body = "Your verification code is: <b>$otp</b>. It expires in 10 minutes.";
            $mail->AltBody = "Your verification code is: $otp. It expires in 10 minutes.";

            // Retry mechanism for sending email (up to 2 attempts)
            $max_attempts = 2;
            for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                try {
                    $mail->send();
                    $_SESSION['otp_sent_time'] = time();
                    return true;
                } catch (Exception $e) {
                    error_log("SMTP Send Attempt $attempt Failed: " . $e->getMessage());
                    if ($attempt == $max_attempts) {
                        throw new Exception("Failed to send OTP after $max_attempts attempts: " . $e->getMessage());
                    }
                    sleep(2); // Wait 2 seconds before retrying
                }
            }
        }
        return false;
    } catch (Exception $e) {
        error_log("Error in sendOTP: " . $e->getMessage());
        return false;
    }
}

// Check if user_id and email are provided
if (isset($_GET["user_id"]) && isset($_GET["email"])) {
    $email_id = filter_var($_GET["email"], FILTER_SANITIZE_EMAIL);
    $user_id = filter_var($_GET["user_id"], FILTER_SANITIZE_NUMBER_INT);

    // Send initial OTP if not already sent
    if (!isset($_SESSION['otp_sent_time'])) {
        if (!sendOTP($email_id, $user_id, $conn)) {
            $resend_error = "Failed to send OTP. Please try again or check your email settings. Detailed logs are in the server error log.";
        }
    }

    // Handle resend OTP request
    if (isset($_GET['resend'])) {
        if (isset($_SESSION['otp_sent_time']) && (time() - $_SESSION['otp_sent_time']) < 60) {
            $resend_error = "Please wait 60 seconds before resending OTP.";
        } else {
            if (sendOTP($email_id, $user_id, $conn)) {
                $resend_success = "OTP resent successfully!";
            } else {
                $resend_error = "Failed to resend OTP. Please try again or check your email settings. Detailed logs are in the server error log.";
            }
        }
    }

    // Handle form submission for verification
    if (isset($_POST["verify"])) {
        $code = trim($_POST["verification_code"]);
        $sql = "SELECT VERIFICATION_CODE FROM CUSTOMER WHERE USER_ID = :userid";
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':userid', $user_id);
        oci_execute($stmt);

        if ($row = oci_fetch_assoc($stmt)) {
            $stored_code = $row['VERIFICATION_CODE'];
            if ($stored_code === $code) {
                $sql_update = "UPDATE CUSTOMER SET VERIFIED_CUSTOMER = :verified_customer, VERIFICATION_CODE = NULL WHERE USER_ID = :userid";
                $stmt_update = oci_parse($conn, $sql_update);
                $verified_customer = 1;
                oci_bind_by_name($stmt_update, ':verified_customer', $verified_customer);
                oci_bind_by_name($stmt_update, ':userid', $user_id);

                if (oci_execute($stmt_update)) {
                    oci_free_statement($stmt_update);
                    oci_close($conn);
                    unset($_SESSION['otp_sent_time']);
                    header("Location: customer_signin.php");
                    exit();
                } else {
                    $verification_error = "Failed to update verification status.";
                }
            } else {
                $verification_error = "Incorrect Verification Code!";
            }
        } else {
            $verification_error = "Invalid user or verification data.";
        }

        oci_free_statement($stmt);
    }
    oci_close($conn);
} else {
    header("Location: customer_signup.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ClickFax Traders - Verify Your Email</title>
    <link rel="icon" href="logo_ico.png" type="image/png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@0.9.4/css/bulma.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f0f8ff;
            font-family: 'Arial', sans-serif;
        }
        .verify-container {
            max-width: 500px;
            margin: 3rem auto;
            background-color: #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            border-radius: 8px;
        }
        .title {
            color: #363636;
            margin-bottom: 1.5rem;
        }
        .error-message {
            color: #ff3860;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            text-align: center;
        }
        .success-message {
            color: #23d160;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            text-align: center;
        }
        .instruction-text {
            color: #7a7a7a;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .input {
            border-color: #dbdbdb;
        }
        .button.is-primary {
            background-color: #3273dc;
            border-color: transparent;
        }
        .button.is-primary:hover {
            background-color: #2366d1;
        }
        .resend-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: #3273dc;
            text-decoration: none;
        }
        .resend-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <nav class="navbar is-light" role="navigation" aria-label="main navigation">
        <div class="navbar-brand">
            <a class="navbar-item logo-container" href="index.php">
                <img src="logo.png" alt="ClickFax Traders Logo" class="header-logo">
            </a>
            <a role="button" class="navbar-burger" aria-label="menu" aria-expanded="false" data-target="navbarMenu">
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
            </a>
        </div>
        <div id="navbarMenu" class="navbar-menu">
            <div class="navbar-start">
                <a class="navbar-item nav-link" href="productlisting.php">Shop</a>
                <a class="navbar-item nav-link" href="about.php">About Us</a>
                <a class="navbar-item nav-link" href="productlisting.php">Products</a>
            </div>
            <div class="navbar-end">
                <div class="navbar-item">
                    <input class="input" type="text" placeholder="Search products..." style="max-width: 200px;">
                </div>
                <div class="navbar-item">
                    <a class="button is-light" href="cart.php">
                        <span class="icon"><i class="fas fa-shopping-cart"></i></span>
                        <span>Cart (0)</span>
                    </a>
                </div>
                <div class="navbar-item">
                    <a class="button is-primary" href="customer_signin.php">Login</a>
                </div>
                <div class="navbar-item">
                    <a class="button is-success" href="traderregister.php">Become a trader</a>
                </div>
            </div>
        </div>
    </nav>

    <section class="section">
        <div class="verify-container">
            <h2 class="title has-text-centered">Verify Your Email</h2>
            <?php if (!empty($verification_error)) { ?>
                <p class="error-message"><?php echo htmlspecialchars($verification_error); ?></p>
            <?php } elseif (!empty($resend_error)) { ?>
                <p class="error-message"><?php echo htmlspecialchars($resend_error); ?></p>
            <?php } elseif (!empty($resend_success)) { ?>
                <p class="success-message"><?php echo htmlspecialchars($resend_success); ?></p>
            <?php } else { ?>
                <p class="instruction-text">Please enter the verification code sent to your email: <span class="has-text-weight-semibold"><?php echo htmlspecialchars($email_id); ?></span></p>
            <?php } ?>
            <form method="POST" action="">
                <div class="field">
                    <label class="label" for="verification_code">Verification Code</label>
                    <div class="control">
                        <input class="input" type="text" id="verification_code" name="verification_code" required maxlength="6" placeholder="Enter 6-digit code">
                    </div>
                </div>
                <div class="field">
                    <div class="control">
                        <button type="submit" name="verify" class="button is-primary is-fullwidth">Verify Code</button>
                    </div>
                </div>
            </form>
            <a href="?user_id=<?php echo urlencode($user_id); ?>&email=<?php echo urlencode($email_id); ?>&resend=1" class="resend-link">Didn't receive the code? Resend Code</a>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <div class="columns">
                <div class="column is-half">
                    <div class="footer-logo">
                        <a href="index.php">
                            <img src="logo.png" alt="ClickFax Traders Logo" class="footer-logo-img">
                        </a>
                    </div>
                    <p class="title is-4">ClickFax Traders</p>
                    <p>Email: <a href="mailto:info@clickfaxtraders.com">info@clickfaxtraders.com</a></p>
                    <p>Phone: <a href="tel:+16466755074">646-675-5074</a></p>
                    <p>3961 Smith Street, New York, United States</p>
                    <div class="buttons mt-4">
                        <a href="https://www.facebook.com/clickfaxtraders" class="button is-small" target="_blank">
                            <span class="icon"><i class="fab fa-facebook-f"></i></span>
                        </a>
                        <a href="https://www.twitter.com/clickfaxtraders" class="button is-small" target="_blank">
                            <span class="icon"><i class="fab fa-twitter"></i></span>
                        </a>
                        <a href="https://www.instagram.com/clickfaxtraders" class="button is-small" target="_blank">
                            <span class="icon"><i class="fab fa-instagram"></i></span>
                        </a>
                    </div>
                </div>
                <div class="column is-half">
                    <h2 class="title is-4">Contact Us</h2>
                    <form method="post" action="/contact">
                        <div class="field">
                            <label class="label" for="name">Name</label>
                            <div class="control">
                                <input class="input" type="text" id="name" name="name" placeholder="Name" required>
                            </div>
                        </div>
                        <div class="field">
                            <label class="label" for="email">Email</label>
                            <div class="control">
                                <input class="input" type="email" id="email" name="email" placeholder="Email" required>
                            </div>
                        </div>
                        <div class="field">
                            <label class="label" for="message">Message</label>
                            <div class="control">
                                <textarea class="textarea" id="message" name="message" placeholder="Type your message here..." required></textarea>
                            </div>
                        </div>
                        <div class="field">
                            <div class="control">
                                <button class="button is-primary" type="submit">Send</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const $navbarBurgers = Array.prototype.slice.call(document.querySelectorAll('.navbar-burger'), 0);
            if ($navbarBurgers.length > 0) {
                $navbarBurgers.forEach(el => {
                    el.addEventListener('click', () => {
                        const target = el.dataset.target;
                        const $target = document.getElementById(target); // Fixed typo
                        el.classList.toggle('is-active');
                        $target.classList.toggle('is-active');
                    });
                });
            }
        });
    </script>
</html>
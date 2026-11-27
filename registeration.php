<?php
require_once __DIR__ . '/config.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/** Store an uploaded JPG/PNG/PDF using the detected MIME type, never the client's name. */
function handleUpload(string $key, string $prefix, array &$errors, string $customName = ''): string
{
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'application/pdf' => 'pdf',
    ];
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $file = $_FILES[$key];
    if ($file['size'] > 10 * 1024 * 1024) {
        $errors[] = ucfirst($prefix) . ' file must be under 10 MB.';
        return '';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        $errors[] = ucfirst($prefix) . ' must be a JPG, PNG or PDF file.';
        return '';
    }
    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        $errors[] = 'The server could not store the upload. Please try again later.';
        return '';
    }
    $name = $customName ?: $prefix . '_' . time() . '_' . bin2hex(random_bytes(8));
    $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $name)) {
        $errors[] = 'The server could not store the upload. Please try again later.';
        return '';
    }
    return $name;
}

function generateRegTempId(PDO $pdo): string
{
    // ICNHAEM-REG- is 12 characters, so the sequence starts at position 13.
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(reg_temp_id, 13) AS UNSIGNED)) AS max_num FROM registrations");
    $row = $stmt->fetch();
    return 'ICNHAEM-REG-' . str_pad((int)($row['max_num'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
}

function fail(array $errors, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => implode(' ', $errors),
        'errors'  => $errors,
    ]);
    exit;
}

/* ---------- Abstract ID lookup ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['lookup_abstract'])) {
    header('Content-Type: application/json');
    try {
        $id = trim((string)$_GET['lookup_abstract']);
        if ($id === '') {
            echo json_encode(['found' => false, 'error' => 'Please enter an abstract ID.']);
            exit;
        }
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT temp_id, full_abstract_id, full_name, abstract_title, comm_author_name
                               FROM abstracts WHERE temp_id = :id OR full_abstract_id = :id2 LIMIT 1");
        $stmt->execute([':id' => $id, ':id2' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode([
                'found'  => true,
                'title'  => $row['abstract_title'],
                'author' => $row['comm_author_name'] ?: $row['full_name'],
            ]);
        } else {
            echo json_encode(['found' => false, 'error' => 'No abstract found with this ID.']);
        }
    } catch (Throwable $ex) {
        error_log('ICNHAEM lookup error: ' . $ex->getMessage());
        echo json_encode(['found' => false, 'error' => 'Lookup is temporarily unavailable.']);
    }
    exit;
}

/* ---------- Registration submission ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    try {
        $postedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
            fail(['Invalid or expired session. Please refresh the page and try again.'], 403);
        }
        if (!empty($_POST['website'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        $now = time();
        if (isset($_SESSION['last_submit']) && ($now - $_SESSION['last_submit']) < 30) {
            fail(['Please wait a moment before submitting again.'], 429);
        }

        $fullName          = trim($_POST['full_name'] ?? '');
        $gender            = trim($_POST['gender'] ?? '');
        $email             = trim($_POST['email'] ?? '');
        $mobile            = trim($_POST['mobile'] ?? '');
        $designation       = trim($_POST['designation'] ?? '');
        $department        = trim($_POST['department'] ?? '');
        $institute         = trim($_POST['institute'] ?? '');
        $address           = trim($_POST['address'] ?? '');
        $country           = trim($_POST['country'] ?? '');
        $participationType = trim($_POST['participation_type'] ?? '');
        $themes            = (isset($_POST['themes']) && is_array($_POST['themes'])) ? implode(', ', array_map('strval', $_POST['themes'])) : '';
        $publishJournal    = trim($_POST['publish_journal'] ?? '');
        $abstractId        = trim($_POST['abstract_id'] ?? '');
        $feeCategory       = trim($_POST['fee_category'] ?? '');
        $feePaid           = trim($_POST['fee_paid'] ?? '');
        $currency          = trim($_POST['currency'] ?? 'INR');
        $paymentDate       = trim($_POST['payment_date'] ?? '');
        $transactionId     = trim($_POST['transaction_id'] ?? '');
        $agreed            = isset($_POST['agreed']) ? 1 : 0;

        if ($fullName === '') $errors[] = 'Full name is required.';
        if ($gender === '') $errors[] = 'Gender is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid e-mail address is required.';
        if ($mobile === '') $errors[] = 'Mobile number is required.';
        if ($institute === '') $errors[] = 'Institute / organisation is required.';
        if ($country === '') $errors[] = 'Country is required.';
        if ($participationType === '') $errors[] = 'Participation type is required.';
        if ($publishJournal === '') $errors[] = 'Please select a publication option.';
        if ($publishJournal !== 'Registration without publication' && $abstractId === '') {
            $errors[] = 'Abstract ID is required when registering with publication.';
        }
        if ($feeCategory === '') $errors[] = 'Registration category is required.';
        if ($feePaid === '' || !is_numeric($feePaid)) $errors[] = 'A valid fee amount is required.';
        if ($paymentDate === '') $errors[] = 'Date of payment is required.';
        if ($transactionId === '') $errors[] = 'Payment transaction ID is required.';
        if (!$agreed) $errors[] = 'Please agree to the terms before submitting.';

        $noFile = function ($k) {
            return !isset($_FILES[$k]) || $_FILES[$k]['error'] === UPLOAD_ERR_NO_FILE;
        };
        $paymentProof = handleUpload('payment_proof', 'payment', $errors, 'PAY_' . $transactionId);
        if ($paymentProof === '' && $noFile('payment_proof')) {
            $errors[] = 'Payment proof upload is required.';
        }
        $isStudent = strpos($feeCategory, 'PhD') !== false;
        $studentDoc = $isStudent ? handleUpload('student_doc', 'student', $errors, 'ID_' . $transactionId) : '';
        if ($isStudent && $studentDoc === '' && $noFile('student_doc')) {
            $errors[] = 'A student proof document is required for PhD student registration.';
        }

        if ($errors) fail($errors);

        $pdo = getDB();
        $regTempId = generateRegTempId($pdo);

        $abstractTitle = '';
        if ($abstractId !== '') {
            try {
                $absStmt = $pdo->prepare("SELECT abstract_title FROM abstracts WHERE temp_id = :id OR full_abstract_id = :id2 LIMIT 1");
                $absStmt->execute([':id' => $abstractId, ':id2' => $abstractId]);
                $abstractTitle = (string)($absStmt->fetchColumn() ?: '');
            } catch (Throwable $e) {
                // Lookup is optional; continue with the submitted ID.
            }
        }

        $stmt = $pdo->prepare("INSERT INTO registrations
            (reg_temp_id, full_name, gender, email, mobile, designation, department, institute, address, country,
             participation_type, themes, publish_journal, abstract_id, fee_category, fee_paid, currency,
             payment_date, transaction_id, payment_proof, student_doc, agreed)
            VALUES
            (:reg_temp_id, :full_name, :gender, :email, :mobile, :designation, :department, :institute, :address, :country,
             :participation_type, :themes, :publish_journal, :abstract_id, :fee_category, :fee_paid, :currency,
             :payment_date, :transaction_id, :payment_proof, :student_doc, :agreed)");
        $stmt->execute([
            ':reg_temp_id' => $regTempId,
            ':full_name' => $fullName,
            ':gender' => $gender,
            ':email' => $email,
            ':mobile' => $mobile,
            ':designation' => $designation ?: null,
            ':department' => $department ?: null,
            ':institute' => $institute,
            ':address' => $address ?: null,
            ':country' => $country,
            ':participation_type' => $participationType,
            ':themes' => $themes ?: null,
            ':publish_journal' => $publishJournal ?: null,
            ':abstract_id' => $abstractId ?: null,
            ':fee_category' => $feeCategory,
            ':fee_paid' => $feePaid,
            ':currency' => $currency,
            ':payment_date' => $paymentDate,
            ':transaction_id' => $transactionId,
            ':payment_proof' => $paymentProof,
            ':student_doc' => $studentDoc ?: null,
            ':agreed' => $agreed,
        ]);

        $safeName = preg_replace('/[\r\n]+/', ' ', $fullName);
        $subject = CONF_NAME . " Registration - $safeName";
        $message = "NEW REGISTRATION — " . CONF_NAME . "\n\n";
        $message .= "Registration ID:   $regTempId\n";
        $message .= "Name:              $fullName\n";
        $message .= "Gender:            $gender\n";
        $message .= "E-mail:            $email\n";
        $message .= "Mobile:            $mobile\n";
        $message .= "Designation:       $designation\n";
        $message .= "Department:        $department\n";
        $message .= "Institute:         $institute\n";
        $message .= "Country:           $country\n";
        $message .= "Participation:     $participationType\n";
        $message .= "Themes:            $themes\n";
        $message .= "Publication:       $publishJournal\n";
        if ($abstractId !== '') $message .= "Abstract ID:       $abstractId\n";
        if ($abstractTitle !== '') $message .= "Abstract title:    $abstractTitle\n";
        $message .= "Category:          $feeCategory\n";
        $message .= "Fee paid:          $currency $feePaid\n";
        $message .= "Payment date:      $paymentDate\n";
        $message .= "Transaction ID:    $transactionId\n";
        $message .= "Payment proof:     " . ($paymentProof ?: 'N/A') . "\n";
        $message .= "Student proof:     " . ($studentDoc ?: 'N/A') . "\n";
        $message .= "Submitted:         " . date('Y-m-d H:i:s') . "\n";

        $headers = 'From: ' . SITE_EMAIL . "\r\n" .
                   'Reply-To: ' . SITE_EMAIL . "\r\n" .
                   "Content-Type: text/plain; charset=utf-8\r\n";
        foreach (NOTIFY_EMAILS as $notifyEmail) {
            @mail($notifyEmail, $subject, $message, $headers);
        }

        $confirmSubject = CONF_NAME . ": Registration Received - $regTempId";
        $confirmMessage = "Dear $safeName,\n\nThank you for registering for " . CONF_FULL_NAME . " (" . CONF_NAME . ").\n\n";
        $confirmMessage .= "Your temporary registration ID is: $regTempId\n";
        $confirmMessage .= "Please quote this ID in all future correspondence. We will review your payment details and share confirmation separately.\n\n";
        if ($abstractId !== '') $confirmMessage .= "Abstract ID: $abstractId\n";
        $confirmMessage .= "Fee paid: $currency $feePaid\nTransaction ID: $transactionId\n\n";
        $confirmMessage .= "Conference: " . CONF_DATES . "\nVenue: " . CONF_VENUE . "\n\n";
        $confirmMessage .= "Best regards,\n" . CONF_NAME . " Organising Committee\n" . SITE_EMAIL . "\n";
        @mail($email, $confirmSubject, $confirmMessage, $headers);

        $_SESSION['last_submit'] = $now;
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'reg_temp_id' => $regTempId]);
        exit;
    } catch (Throwable $ex) {
        error_log('ICNHAEM registration error: ' . $ex->getMessage());
        fail(['A server error occurred. Please try again later or contact ' . SITE_EMAIL . '.'], 500);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration | ICNHAEM 2027</title>
    <meta name="description" content="Register for ICNHAEM-2027, 18–20 March 2027 at Banaras Hindu University, Varanasi. View fees and complete the online registration form.">
    <link rel="canonical" href="https://icnhaem2027.com/registration.php">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header class="site-header">
    <div class="container header-inner">
        <a href="index.html" class="brand" aria-label="ICNHAEM 2027 home">
            <span class="brand-mark">I<span>27</span></span>
            <span class="brand-text"><span class="brand-title">ICNHAEM 2027</span><span class="brand-sub">BHU · Varanasi</span></span>
        </a>
        <nav class="nav" aria-label="Primary navigation">
            <a href="index.html" class="nav-link">Home</a>
            <a href="about.html" class="nav-link">About</a>
            <a href="abstract.php" class="nav-link">Abstract</a>
            <a href="registration.php" class="nav-link">Registration</a>
            <a href="contact.html" class="nav-link">Contact</a>
        </nav>
        <a href="registration.php" class="btn btn-primary btn-small nav-cta">Register now <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span></a>
        <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mobileNav"><span class="material-symbols-rounded" aria-hidden="true">menu</span></button>
    </div>
    <nav class="mobile-nav" id="mobileNav" aria-label="Mobile navigation">
        <a href="index.html">Home</a>
        <a href="about.html">About</a>
        <a href="abstract.php">Abstract</a>
        <a href="registration.php">Registration</a>
        <a href="contact.html">Contact</a>
        <a href="registration.php" class="mobile-cta">Register now</a>
    </nav>
</header>

<main>
    <section class="page-hero">
        <div class="container">
            <div class="breadcrumb"><a href="index.html">Home</a><span>/</span>Registration</div>
            <h1>Registration</h1>
            <p>Join us at Banaras Hindu University, Varanasi, for three days of research and discussion.</p>
        </div>
    </section>

    <section class="section" id="fees">
        <div class="container">

            <div class="section-head reveal">
                <span class="eyebrow">Participation</span>
                <h2 class="section-title">Registration fees</h2>
                <p class="section-lead">As per the conference brochure. Details for international participants will be announced separately.</p>
            </div>

            <div class="table-wrap reveal">
                <table>
                    <thead><tr><th>Category</th><th>Fee (INR)</th><th>Details</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Faculty</strong></td><td>&#8377;5,000</td><td>Teaching and research faculty</td></tr>
                        <tr><td><strong>Post Doctoral Fellow</strong></td><td>&#8377;3,000</td><td>With a valid post-doc position</td></tr>
                        <tr><td><strong>PhD Student</strong></td><td>&#8377;2,500</td><td>Valid student proof required</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="notice notice-info mt-2 reveal">
                <span class="material-symbols-rounded" aria-hidden="true">info</span>
                <span>Registration includes access to all technical sessions, conference material and refreshments. Payment details and deadlines will be confirmed by the organising committee.</span>
            </div>

            <div class="form-card mt-2 reveal">
                <span class="eyebrow">Online form</span>
                <h2 class="panel-title">Complete your registration</h2>
                <p style="font-size:.9rem; color:var(--ink-soft); margin-bottom:24px;">Fields marked <span class="req">*</span> are mandatory. Have your payment receipt and abstract ID (if any) ready.</p>

                <form id="registrationForm" action="registration.php" method="POST" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="sr-only" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                    <div class="alert alert-error" id="formErrors" role="alert"><h4>Please review the following</h4><ul id="errorList"></ul></div>
                    <div class="alert alert-success" id="formSuccess" role="status">
                        <h4>Registration received</h4>
                        <p>Your temporary registration ID is <strong id="successRegId"></strong>. Please keep it for future correspondence — a confirmation has been sent to your e-mail.</p>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title"><span class="material-symbols-rounded" aria-hidden="true">person</span> Participant details</h3>
                        <div class="form-grid">
                            <div class="field full"><label for="full_name">Full name <span class="req">*</span></label><input id="full_name" name="full_name" type="text" required placeholder="Full name"></div>
                            <div class="field"><label for="gender">Gender <span class="req">*</span></label><select id="gender" name="gender" required><option value="">Select</option><option>Male</option><option>Female</option><option>Other</option><option>Prefer not to say</option></select></div>
                            <div class="field"><label for="email">E-mail address <span class="req">*</span></label><input id="email" name="email" type="email" required placeholder="you@university.edu"></div>
                            <div class="field"><label for="mobile">Mobile number <span class="req">*</span></label><input id="mobile" name="mobile" type="tel" required placeholder="+91-XXXXXXXXXX"></div>
                            <div class="field"><label for="country">Country <span class="req">*</span></label><input id="country" name="country" type="text" required placeholder="India"></div>
                            <div class="field"><label for="designation">Designation</label><input id="designation" name="designation" type="text" placeholder="Professor, Research Scholar, …"></div>
                            <div class="field"><label for="department">Department</label><input id="department" name="department" type="text" placeholder="Physics, Chemistry, …"></div>
                            <div class="field full"><label for="institute">Institute / university / organisation <span class="req">*</span></label><input id="institute" name="institute" type="text" required placeholder="Full name of your institution"></div>
                            <div class="field full"><label for="address">Postal address</label><textarea id="address" name="address" placeholder="Street, city, state, postal code"></textarea></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title"><span class="material-symbols-rounded" aria-hidden="true">badge</span> Participation &amp; publication</h3>
                        <div class="form-grid">
                            <div class="field"><label for="participation_type">Participation type <span class="req">*</span></label><select id="participation_type" name="participation_type" required><option value="">Select</option><option>Oral presenter</option><option>Poster presenter</option><option>Attendee only</option></select></div>
                            <div class="field"><label for="publish_journal">Publication option <span class="req">*</span></label><select id="publish_journal" name="publish_journal" required><option value="">Select</option><option>Publication in an indexed journal</option><option>Registration without publication</option></select></div>
                            <div class="field full"><label for="abstract_id">Abstract ID <span class="req">*</span></label><input id="abstract_id" name="abstract_id" type="text" placeholder="e.g. ICNHAEM-ABS-0001"><span class="field-hint" id="lookupResult">Required if you chose a publication option. e.g. ICNHAEM-ABS-0001</span></div>
                            <div class="field full">
                                <span class="field-label">Thematic areas of interest</span>
                                <div class="checkbox-group">
                                    <label class="checkbox-field"><input type="checkbox" name="themes[]" value="Nanomaterials for Energy and Functional Applications"> Nanomaterials for Energy and Functional Applications</label>
                                    <label class="checkbox-field"><input type="checkbox" name="themes[]" value="Hydrogen Generation, Storage and Utilisation"> Hydrogen Generation, Storage and Utilisation</label>
                                    <label class="checkbox-field"><input type="checkbox" name="themes[]" value="Advanced Energy Materials and Storage Devices"> Advanced Energy Materials and Storage Devices</label>
                                    <label class="checkbox-field"><input type="checkbox" name="themes[]" value="Emerging and Sustainable Energy Technologies"> Emerging and Sustainable Energy Technologies</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title"><span class="material-symbols-rounded" aria-hidden="true">payments</span> Fee &amp; payment</h3>
                        <div class="form-grid">
                            <div class="field"><label for="fee_category">Registration category <span class="req">*</span></label><select id="fee_category" name="fee_category" required onchange="document.getElementById('studentDocField').style.display=this.value.indexOf('PhD')!==-1?'flex':'none'"><option value="">Select</option><option>Faculty — &#8377;5,000</option><option>Post Doc — &#8377;3,000</option><option>PhD Student — &#8377;2,500</option></select></div>
                            <div class="field"><label for="fee_paid">Fee paid <span class="req">*</span></label><input id="fee_paid" name="fee_paid" type="number" min="1" step="1" required placeholder="e.g. 5000"></div>
                            <div class="field"><label for="currency">Currency</label><select id="currency" name="currency"><option>INR</option><option>USD</option></select></div>
                            <div class="field"><label for="payment_date">Date of payment <span class="req">*</span></label><input id="payment_date" name="payment_date" type="date" required></div>
                            <div class="field"><label for="transaction_id">Payment transaction ID <span class="req">*</span></label><input id="transaction_id" name="transaction_id" type="text" required placeholder="Receipt / UPI / reference number"></div>
                            <div class="field"><label for="payment_proof">Payment proof (JPG / PNG / PDF) <span class="req">*</span></label><input id="payment_proof" name="payment_proof" type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required><span class="field-hint">Maximum file size: 10 MB</span></div>
                            <div class="field" id="studentDocField" style="display:none;"><label for="student_doc">Student proof (JPG / PNG / PDF) <span class="req">*</span></label><input id="student_doc" name="student_doc" type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"><span class="field-hint">Required for PhD student registration</span></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <label class="checkbox-field"><input type="checkbox" name="agreed" value="1" required><span>I confirm the details above are correct and agree to the registration terms of ICNHAEM-2027. <span class="req">*</span></span></label>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary">Complete registration <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span></button>
                            <span class="form-footer-note">You will receive a registration ID by e-mail.</span>
                        </div>
                    </div>
                </form>
            </div>

        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="index.html" class="brand"><span class="brand-mark">I<span>27</span></span><span class="brand-text"><span class="brand-title">ICNHAEM 2027</span><span class="brand-sub">BHU · Varanasi</span></span></a>
                <p>International Conference on Nanomaterials, Hydrogen and Advanced Energy Materials and Devices.</p>
                <p>18–20 March 2027 · Banaras Hindu University, Varanasi, India</p>
            </div>
            <div><h4>Explore</h4><ul class="footer-links"><li><a href="index.html">Home</a></li><li><a href="about.html">About</a></li><li><a href="abstract.php">Abstract submission</a></li><li><a href="registration.php">Registration</a></li><li><a href="contact.html">Contact</a></li></ul></div>
            <div><h4>Conference</h4><ul class="footer-links"><li><a href="about.html#themes">Thematic areas</a></li><li><a href="about.html#committee">Committee</a></li><li><a href="about.html#dedication">Dedication</a></li><li><a href="registration.php#fees">Registration fees</a></li></ul></div>
            <div><h4>Get in touch</h4><div class="footer-line"><span class="material-symbols-rounded" aria-hidden="true">mail</span><a href="mailto:info@icnhaem2027.com">info@icnhaem2027.com</a></div><div class="footer-line"><span class="material-symbols-rounded" aria-hidden="true">location_on</span><span>Banaras Hindu University<br>Varanasi, Uttar Pradesh, India</span></div><div class="footer-line"><span class="material-symbols-rounded" aria-hidden="true">language</span><a href="https://icnhaem2027.com">icnhaem2027.com</a></div></div>
        </div>
        <div class="footer-bottom"><p>&copy; 2027 ICNHAEM. All rights reserved.</p><p>Organised by the students of Prof. O. N. Srivastava &amp; Onkar Nath Educational Society</p></div>
    </div>
</footer>

<script src="js/main.js"></script>
<script src="js/registration.js"></script>
</body>
</html>

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

/** Validate and store the uploaded abstract; returns the stored filename or ''. */
function handleAbstractUpload(string $key, array &$errors): string
{
    $allowed = [
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $file = $_FILES[$key];
    if ($file['size'] > 10 * 1024 * 1024) {
        $errors[] = 'Abstract file must be under 10 MB.';
        return '';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        $errors[] = 'Abstract must be a Word document (.doc or .docx).';
        return '';
    }
    if (!is_dir(ABSTRACT_UPLOAD_DIR) && !mkdir(ABSTRACT_UPLOAD_DIR, 0755, true) && !is_dir(ABSTRACT_UPLOAD_DIR)) {
        $errors[] = 'The server could not store the upload. Please try again later.';
        return '';
    }
    $name = 'abstract_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], ABSTRACT_UPLOAD_DIR . $name)) {
        $errors[] = 'The server could not store the upload. Please try again later.';
        return '';
    }
    return $name;
}

function generateTempId(PDO $pdo): string
{
    // ICNHAEM-ABS- is 12 characters, so the sequence starts at position 13.
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(temp_id, 13) AS UNSIGNED)) AS max_num FROM abstracts");
    $row = $stmt->fetch();
    return 'ICNHAEM-ABS-' . str_pad((int)($row['max_num'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    try {
        $postedToken = $_POST['csrf_token'] ?? '';
        if (!is_string($postedToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
            fail(['Invalid or expired session. Please refresh the page and try again.'], 403);
        }
        // Honeypot — real visitors never fill this hidden field.
        if (!empty($_POST['website'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        $now = time();
        if (isset($_SESSION['last_submit']) && ($now - $_SESSION['last_submit']) < 30) {
            fail(['Please wait a moment before submitting again.'], 429);
        }

        $fullName        = trim($_POST['full_name'] ?? '');
        $gender          = trim($_POST['gender'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $mobile          = trim($_POST['mobile'] ?? '');
        $designation     = trim($_POST['designation'] ?? '');
        $department      = trim($_POST['department'] ?? '');
        $institute       = trim($_POST['institute'] ?? '');
        $address         = trim($_POST['address'] ?? '');
        $country         = trim($_POST['country'] ?? '');
        $abstractTitle   = trim($_POST['abstract_title'] ?? '');
        $presentationPref = trim($_POST['presentation_preference'] ?? '');
        $agreed          = isset($_POST['agreed']) ? 1 : 0;

        $hasCommAuthor = ($_POST['comm_author_check'] ?? '0') === '1';
        $commAuthorName       = $hasCommAuthor ? trim($_POST['comm_author_name'] ?? '') : '';
        $commAuthorEmail      = $hasCommAuthor ? trim($_POST['comm_author_email'] ?? '') : '';
        $commAuthorAffiliation = $hasCommAuthor ? trim($_POST['comm_author_affiliation'] ?? '') : '';

        if ($fullName === '') $errors[] = 'Full name is required.';
        if ($gender === '') $errors[] = 'Gender is required.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid e-mail address is required.';
        if ($mobile === '') $errors[] = 'Mobile number is required.';
        if ($institute === '') $errors[] = 'Institute / organisation is required.';
        if ($country === '') $errors[] = 'Country is required.';
        if ($abstractTitle === '') $errors[] = 'Abstract title is required.';
        if ($presentationPref === '') $errors[] = 'Presentation preference is required.';
        if ($hasCommAuthor) {
            if ($commAuthorName === '') $errors[] = 'Corresponding author name is required.';
            if ($commAuthorEmail === '' || !filter_var($commAuthorEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid corresponding author e-mail is required.';
            if ($commAuthorAffiliation === '') $errors[] = 'Corresponding author affiliation is required.';
        }
        if (!$agreed) $errors[] = 'Please agree to the terms before submitting.';

        $abstractFile = handleAbstractUpload('abstract_file', $errors);
        $noFile = !isset($_FILES['abstract_file']) || $_FILES['abstract_file']['error'] === UPLOAD_ERR_NO_FILE;
        if ($abstractFile === '' && $noFile) {
            $errors[] = 'An abstract Word document is required.';
        }

        if ($errors) fail($errors);

        $pdo = getDB();
        $tempId = generateTempId($pdo);
        $ext = pathinfo($abstractFile, PATHINFO_EXTENSION);
        $newName = $tempId . '.' . $ext;
        $oldPath = ABSTRACT_UPLOAD_DIR . $abstractFile;
        if (file_exists($oldPath)) {
            rename($oldPath, ABSTRACT_UPLOAD_DIR . $newName);
            $abstractFile = $newName;
        }

        $stmt = $pdo->prepare("INSERT INTO abstracts
            (temp_id, full_name, gender, email, mobile, designation, department, institute, address, country,
             abstract_title, abstract_file, comm_author_name, comm_author_email, comm_author_affiliation,
             presentation_preference, agreed)
            VALUES
            (:temp_id, :full_name, :gender, :email, :mobile, :designation, :department, :institute, :address, :country,
             :abstract_title, :abstract_file, :comm_author_name, :comm_author_email, :comm_author_affiliation,
             :presentation_preference, :agreed)");
        $stmt->execute([
            ':temp_id' => $tempId,
            ':full_name' => $fullName,
            ':gender' => $gender,
            ':email' => $email,
            ':mobile' => $mobile,
            ':designation' => $designation ?: null,
            ':department' => $department ?: null,
            ':institute' => $institute,
            ':address' => $address ?: null,
            ':country' => $country,
            ':abstract_title' => $abstractTitle,
            ':abstract_file' => $abstractFile,
            ':comm_author_name' => $commAuthorName ?: null,
            ':comm_author_email' => $commAuthorEmail ?: null,
            ':comm_author_affiliation' => $commAuthorAffiliation ?: null,
            ':presentation_preference' => $presentationPref,
            ':agreed' => $agreed,
        ]);

        $safeName = preg_replace('/[\r\n]+/', ' ', $fullName);
        $subject = CONF_NAME . " Abstract Submission - $tempId - $safeName";
        $message = "NEW ABSTRACT SUBMISSION — " . CONF_NAME . "\n\n";
        $message .= "Abstract ID:       $tempId\n";
        $message .= "Name:              $fullName\n";
        $message .= "Gender:            $gender\n";
        $message .= "E-mail:            $email\n";
        $message .= "Mobile:            $mobile\n";
        $message .= "Designation:       $designation\n";
        $message .= "Department:        $department\n";
        $message .= "Institute:         $institute\n";
        $message .= "Address:           $address\n";
        $message .= "Country:           $country\n";
        $message .= "Title:             $abstractTitle\n";
        $message .= "Presentation:      $presentationPref\n";
        $message .= "File:              " . ($abstractFile ?: 'N/A') . "\n";
        if ($hasCommAuthor) {
            $message .= "Corresponding:     $commAuthorName ($commAuthorEmail), $commAuthorAffiliation\n";
        }
        $message .= "Submitted:         " . date('Y-m-d H:i:s') . "\n";

        $headers = 'From: ' . SITE_EMAIL . "\r\n" .
                   'Reply-To: ' . SITE_EMAIL . "\r\n" .
                   "Content-Type: text/plain; charset=utf-8\r\n";
        foreach (NOTIFY_EMAILS as $notifyEmail) {
            @mail($notifyEmail, $subject, $message, $headers);
        }

        $confirmSubject = CONF_NAME . ": Abstract Received - $tempId";
        $confirmMessage = "Dear $safeName,\n\nThank you for submitting your abstract to " . CONF_FULL_NAME . " (" . CONF_NAME . ").\n\n";
        $confirmMessage .= "Your Abstract ID is: $tempId\n";
        $confirmMessage .= "Please quote this ID in all future correspondence.\n\n";
        $confirmMessage .= "Title: $abstractTitle\n";
        $confirmMessage .= "Presentation preference: $presentationPref\n\n";
        $confirmMessage .= "Conference: " . CONF_DATES . "\nVenue: " . CONF_VENUE . "\n\n";
        $confirmMessage .= "We will notify you of the review decision by e-mail.\n\nBest regards,\n" . CONF_NAME . " Organising Committee\n" . SITE_EMAIL . "\n";
        @mail($email, $confirmSubject, $confirmMessage, $headers);

        $_SESSION['last_submit'] = $now;
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'temp_id' => $tempId]);
        exit;
    } catch (Throwable $ex) {
        error_log('ICNHAEM abstract error: ' . $ex->getMessage());
        fail(['A server error occurred. Please try again later or contact ' . SITE_EMAIL . '.'], 500);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Abstract Submission | ICNHAEM-2027</title>
    <meta name="description" content="Submit your abstract for ICNHAEM-2027, 18–20 March 2027 at Banaras Hindu University, Varanasi.">
    <link rel="canonical" href="https://icnhaem2027.com/abstract.php">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&family=Newsreader:ital,opsz,wght@0,6..72,600;0,6..72,700;1,6..72,500&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<header class="site-header">
    <div class="container header-inner">
        <a href="index.html" class="brand" aria-label="Banaras Hindu University — ICNHAEM 2027 home">
            <img src="images/bhu-full-logo.png" alt="Banaras Hindu University Official Logo" class="brand-logo-full">
        </a>
        <nav class="nav" aria-label="Primary navigation">
            <a href="index.html" class="nav-link">Home</a>
            <a href="about.html" class="nav-link">About &amp; Committee</a>
            <a href="abstract.php" class="nav-link">Abstract</a>
            <a href="registration.php" class="nav-link">Registration</a>
            <a href="contact.html" class="nav-link">Contact</a>
        </nav>
        <a href="registration.php" class="btn btn-primary btn-small nav-cta">Register Now <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span></a>
        <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mobileNav">
            <span class="material-symbols-rounded" aria-hidden="true">menu</span>
        </button>
    </div>
    <nav class="mobile-nav" id="mobileNav" aria-label="Mobile navigation">
        <a href="index.html">Home</a>
        <a href="about.html">About &amp; Committee</a>
        <a href="abstract.php">Abstract</a>
        <a href="registration.php">Registration</a>
        <a href="contact.html">Contact</a>
        <a href="registration.php" class="mobile-cta">Register Now</a>
    </nav>
</header>

<main>
    <section class="page-hero">
        <div class="container">
            <div class="page-hero-top">
                <div class="breadcrumb"><a href="index.html">Home</a><span>/</span>Abstract Submission</div>
                <span class="page-conf-tag">ICNHAEM-2027 · 18–20 March 2027</span>
            </div>
            <h1>Submit an Abstract</h1>
            <p>Share your research at ICNHAEM-2027 — 18–20 March 2027, Banaras Hindu University, Varanasi.</p>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="notice notice-warn reveal">
                <span class="material-symbols-rounded" aria-hidden="true">schedule</span>
                <span><strong>Abstract submission deadlines will be announced soon.</strong> You may prepare and submit your abstract using the template below.</span>
            </div>

            <div class="panel reveal" style="margin-bottom:24px;">
                <span class="eyebrow">Before You Begin</span>
                <h2 class="panel-title">Submission Guidelines</h2>
                <ul style="margin:12px 0 0; padding-left:20px; color:var(--ink-soft);">
                    <li>Submit the abstract as a <strong>Word document (.doc or .docx)</strong>, maximum 10 MB.</li>
                    <li>Keep the abstract to <strong>one page</strong> using the <a href="files/abstract-template.docx"><strong>official abstract template (.docx)</strong></a>.</li>
                    <li>Work must be original, free from plagiarism, and aligned with the four thematic areas of ICNHAEM-2027.</li>
                    <li>All abstracts are peer-reviewed by the scientific committee; decisions are communicated via e-mail.</li>
                    <li>Save the temporary Abstract ID generated upon submission — it is required during registration.</li>
                </ul>
            </div>

            <div class="form-card reveal">
                <form id="abstractForm" action="abstract.php" method="POST" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="sr-only" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                    <div class="alert alert-error" id="formErrors" role="alert"><h4>Please review the following</h4><ul id="errorList"></ul></div>
                    <div class="alert alert-success" id="formSuccess" role="status">
                        <h4>Abstract submitted successfully</h4>
                        <p>Your Abstract ID is <strong id="successTempId"></strong>. Please keep it for future correspondence — a confirmation has been sent to your e-mail.</p>
                    </div>

                    <div class="form-section">
                        <span class="eyebrow">Step 1</span>
                        <h2 class="panel-title">Submitting Author</h2>
                        <p style="font-size:.9rem; margin-bottom:20px;">Fields marked <span class="req">*</span> are mandatory.</p>
                        <div class="form-grid">
                            <div class="field full"><label for="full_name">Full name <span class="req">*</span></label><input id="full_name" name="full_name" type="text" required placeholder="As it should appear in the proceedings"></div>
                            <div class="field"><label for="gender">Gender <span class="req">*</span></label><select id="gender" name="gender" required><option value="">Select</option><option>Male</option><option>Female</option><option>Other</option><option>Prefer not to say</option></select></div>
                            <div class="field"><label for="email">E-mail address <span class="req">*</span></label><input id="email" name="email" type="email" required placeholder="you@university.edu"></div>
                            <div class="field"><label for="mobile">Mobile number <span class="req">*</span></label><input id="mobile" name="mobile" type="tel" required placeholder="+91-XXXXXXXXXX"></div>
                            <div class="field"><label for="country">Country <span class="req">*</span></label><input id="country" name="country" type="text" required placeholder="India"></div>
                            <div class="field"><label for="designation">Designation</label><input id="designation" name="designation" type="text" placeholder="Professor, Research Scholar, ..."></div>
                            <div class="field"><label for="department">Department</label><input id="department" name="department" type="text" placeholder="Physics, Chemistry, Materials Science, ..."></div>
                            <div class="field full"><label for="institute">Institute / university / organisation <span class="req">*</span></label><input id="institute" name="institute" type="text" required placeholder="Full name of your institution"></div>
                            <div class="field full"><label for="address">Postal address</label><textarea id="address" name="address" placeholder="Street, city, state, postal code"></textarea></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title"><span class="material-symbols-rounded" aria-hidden="true">description</span> Abstract Details</h3>
                        <div class="form-grid">
                            <div class="field full"><label for="abstract_title">Title of abstract <span class="req">*</span></label><input id="abstract_title" name="abstract_title" type="text" required placeholder="Enter the full title"></div>
                            <div class="field"><label for="presentation_preference">Presentation preference <span class="req">*</span></label><select id="presentation_preference" name="presentation_preference" required><option value="">Select</option><option>Oral presentation</option><option>Poster presentation</option><option>Either</option></select></div>
                            <div class="field"><label for="abstract_file">Abstract file (.doc / .docx) <span class="req">*</span></label><input id="abstract_file" name="abstract_file" type="file" accept=".doc,.docx,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required><span class="field-hint">Maximum file size: 10 MB</span></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title"><span class="material-symbols-rounded" aria-hidden="true">person_search</span> Corresponding Author <span style="font-weight:400; color:var(--muted); font-size:.84rem;">(optional)</span></h3>
                        <label class="checkbox-field" style="margin-bottom:16px;"><input type="checkbox" id="comm_author_check" name="comm_author_check" value="1"><span>Someone else will be the corresponding author for this abstract</span></label>
                        <div class="form-grid" id="commFields" style="display:none;">
                            <div class="field"><label for="comm_author_name">Name <span class="req">*</span></label><input id="comm_author_name" name="comm_author_name" type="text"></div>
                            <div class="field"><label for="comm_author_email">E-mail <span class="req">*</span></label><input id="comm_author_email" name="comm_author_email" type="email"></div>
                            <div class="field full"><label for="comm_author_affiliation">Affiliation <span class="req">*</span></label><input id="comm_author_affiliation" name="comm_author_affiliation" type="text"></div>
                        </div>
                    </div>

                    <div class="form-section">
                        <label class="checkbox-field"><input type="checkbox" name="agreed" value="1" required><span>I confirm that the work is original and I agree to the submission terms and review process of ICNHAEM-2027. <span class="req">*</span></span></label>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary">Submit Abstract <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span></button>
                            <span class="form-footer-note">You will receive an Abstract ID by e-mail.</span>
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
                <a href="index.html" class="brand" aria-label="Banaras Hindu University">
                    <img src="images/bhu-full-logo.png" alt="Banaras Hindu University Official Logo" class="brand-logo-full">
                </a>
                <div class="footer-conf-name">ICNHAEM <span>2027</span></div>
                <p>International Conference on Nanomaterials, Hydrogen and Advanced Energy Materials and Devices.</p>
                <p>18–20 March 2027 · Banaras Hindu University, Varanasi, India</p>
            </div>
            <div>
                <h4>Explore</h4>
                <ul class="footer-links">
                    <li><a href="index.html">Home</a></li>
                    <li><a href="about.html">About &amp; Committee</a></li>
                    <li><a href="abstract.php">Abstract Submission</a></li>
                    <li><a href="registration.php">Registration</a></li>
                    <li><a href="contact.html">Contact</a></li>
                </ul>
            </div>
            <div>
                <h4>Conference</h4>
                <ul class="footer-links">
                    <li><a href="about.html#themes">Thematic Areas</a></li>
                    <li><a href="about.html#committee">Organizing &amp; Advisory</a></li>
                    <li><a href="about.html#dedication">Prof. O. N. Srivastava</a></li>
                    <li><a href="registration.php#fees">Registration Fees</a></li>
                </ul>
            </div>
            <div>
                <h4>Get in Touch</h4>
                <div class="footer-line"><span class="material-symbols-rounded" aria-hidden="true">mail</span><a href="mailto:info@icnhaem2027.com">info@icnhaem2027.com</a></div>
                <div class="footer-line"><span class="material-symbols-rounded" aria-hidden="true">location_on</span><span>Banaras Hindu University<br>Varanasi, Uttar Pradesh, India</span></div>
                <div class="footer-line"><span class="material-symbols-rounded" aria-hidden="true">language</span><a href="https://icnhaem2027.com">icnhaem2027.com</a></div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2027 ICNHAEM-2027. All rights reserved.</p>
            <p>Organised by Students of Prof. O. N. Srivastava &amp; Onkar Nath Educational Society, Varanasi, India</p>
        </div>
    </div>
</footer>

<script src="js/main.js"></script>
<script src="js/abstract.js"></script>
</body>
</html>

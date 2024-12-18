<?php
// Include necessary files and libraries
require_once 'vendor/phpmailer/PHPMailer.php';
require_once 'vendor/phpmailer/SMTP.php';
require_once 'vendor/phpmailer/Exception.php'; // Assuming you have a PHP mailer library
require_once 'db_connection.php'; // Assuming you have a database connection script
require_once 'vendor/autoload.php';

use Slim\Psr7\Factory\StreamFactory;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Slim\Psr7\Stream;
use PhpOffice\PhpSpreadsheet\IOFactory;




// Initialize the Slim App
$app = AppFactory::create();

$app->addBodyParsingMiddleware();

$app->addErrorMiddleware(true, true, true);


//Define constants and configurations
define('SMTP_SERVER', 'mail.voyants.in');
define('SMTP_PORT', 25);
define('SMTP_USERNAME', 'tanishksingh@voyants.in');
define('SMTP_PASSWORD', 'Rajput@123');
//define('SMTP_PASSWORD', 'Tanishk@2929');
define('SENDER_EMAIL', 'tanishksingh@voyants.in');

//define('SMTP_SERVER', 'smtp.gmail.com');
//define('SMTP_PORT', 587);
//define('SMTP_USERNAME', 'singhtanishk51@gmail.com');
//define('SMTP_PASSWORD', 'Tanishk@29');
//define('SENDER_EMAIL', 'singhtanishk51@gmail.com');

define('UPLOAD_FOLDER', __DIR__ . '/static/images');
define('MYSQL_HOST', 'localhost');
define('MYSQL_PORT', 3306);
define('MYSQL_USER', 'root');
define('MYSQL_PASSWORD', '');
define('MYSQL_DATABASE', 'asset');


// Templating system (You can use any templating engine or method here)
function renderTemplate($templateName) {
    ob_start();
    include_once __DIR__ . '/templates/' . $templateName;
    return ob_get_clean();
}

// Define your route to access the upload folder
$app->get('/upload_folder', function (Request $request, Response $response) {
    // Access the UPLOAD_FOLDER variable here
    $uploadFolder = UPLOAD_FOLDER; // Assuming UPLOAD_FOLDER is defined elsewhere

    // You can now use the $uploadFolder variable as needed
    return $response->withJson(['upload_folder' => $uploadFolder]);
});


$app->post('/send_audit_email', function (Request $request, Response $response) {
    try {
        $data = $request->getParsedBody();
		error_log("Received data: " . json_encode($data));

        $location = $data['location'] ?? '';
        $sbu_name = $data['sbu_name'] ?? '';
			

        // Debugging: log received filters
        error_log("Received filters: location = " . $location . ", sbu_name = " . $sbu_name);

        // Connect to the database
        $connection = get_database_connection();

        // Check connection
        if (!$connection) {
            $response = $response->withStatus(500);
            $response->getBody()->write("Connection failed: " . $connection->errorInfo()[2]);
            return $response;
        }

        // Build the SQL query with filters
        $sql = "SELECT id, asset_name, asset_model, serial_number, email, employee_name FROM accepted_issues WHERE status != 'Confirmed' AND email != 'null'";

        if ($location) {
            $sql .= " AND location = :location";
        }

        if ($sbu_name) {
            $sql .= " AND sbu_name = :sbu_name";
        }

        // Debugging: log constructed SQL query
        error_log("Constructed SQL query: " . $sql);

        $stmt = $connection->prepare($sql);

        // Bind parameters
        if ($location) {
            error_log("Binding location parameter: " . $location);
            $stmt->bindParam(':location', $location);
        }

        if ($sbu_name) {
            error_log("Binding sbu_name parameter: " . $sbu_name);
            $stmt->bindParam(':sbu_name', $sbu_name);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Debugging: log fetched rows
        error_log("Number of rows fetched: " . count($rows));
        foreach ($rows as $row) {
            error_log("Fetched row: " . json_encode($row));
        }

        // Check if rows exist
        if (empty($rows)) {
            $response = $response->withStatus(404);
            $response->getBody()->write("No record found for the provided filters.");
            return $response;
        }

        // Create and send email
        $mail = new PHPMailer(true);
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_SERVER;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPAutoTLS = false;
        $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;

        foreach ($rows as $row) {
            // Recipients
            $mail->setFrom(SENDER_EMAIL, 'Voyants Solutions');
            $mail->addAddress($row['email'], $row['employee_name']);
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Audit for your assets';

            // Define email body
            $emailBody = <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Email Template</title>
    <style>
        .italic-note {
            font-style: italic;
        }
		.bold {
			font-weight: bold;
		}
    </style>
</head>
<body>
    <p class="italic-note">This is an automatic software generated mail, please don't revert back.</p>
    <p>Dear {$row['employee_name']},</p>
    <p>We believe that you have the following assets:</p>
    <ul>
	    <li>Asset Name: {$row['asset_name']}</li>
        <li>Asset Model: {$row['asset_model']}</li>
        <li>Serial Number: {$row['serial_number']}</li>
    </ul>
    <p>Please click <a href="http://localhost:5000/confirm?email={$row['email']}&status=confirmed">YES</a> if you agree, otherwise click <a href="http://localhost:5000/confirm?email={$row['email']}&status=denied">NO</a></p>
		<p>For any other remarks, click <a href="http://localhost:5000/response?id={$row['id']}&name={$row['employee_name']}">here</a></p>
	<p class="bold">Steps to check the Serial Number of your device:</p>
	<p>Step 1: Press Windows + R, type 'cmd' and click on OK to open Command Prompt (or search and open Command Prompt in Windows Search Box)</p>
	<p>Step 2: Type 'wmic bios get serialNumber' and press Enter</P>
	<p> Thanks for your time</p>
	<p class='bold'>Regards</p>
    

</body>
</html>
EOT;

            $mail->Body = $emailBody;
            $mail->send();
            $mail->clearAddresses();
            $mail->clearAllRecipients();
        }

        // Close statement and connection
        $stmt->closeCursor();
        $connection = null;

        // Return a success response
        $response = $response->withStatus(200);
        $response->getBody()->write("Audit email sent successfully.");
        return $response;
    } catch (Exception $e) {
        // Log the error
        error_log("Error sending email: " . $e->getMessage());
        // Return an error response
        $response = $response->withStatus(500);
        $response->getBody()->write("Error sending email: " . $e->getMessage());
        return $response;
    }
});




$app->get('/confirm', function (Request $request, Response $response) {
    $email = $request->getQueryParams()['email'];
    $status = $request->getQueryParams()['status'];
    $currentTime = new DateTime("now", new DateTimeZone('Asia/Kolkata'));
    $indianTime = $currentTime->format('Y-m-d H:i:s');

    try {
        // Connect to the database
        $connection = get_database_connection();

        // Check connection
        if (!$connection) {
            throw new Exception("Database connection failed.");
        }

        // Update the status in the database
        $sql = "UPDATE accepted_issues SET status = :status, confirmed_at = :confirmed_at WHERE email = :email";
        $stmt = $connection->prepare($sql);
        $stmt->execute(['status' => $status, 'confirmed_at' => $indianTime, 'email' => $email]);

        // Close statement and connection
        $stmt->closeCursor();
        $connection = null;

        // Return a success response
        $response = $response->withStatus(200);
        $response->getBody()->write("Status updated successfully to Confirmed at Indian Standard Time: $indianTime.");
        return $response;
    } catch (Exception $e) {
        // Log the error
        error_log("Error updating status: " . $e->getMessage());
        // Return an error response
        $response = $response->withStatus(500);
        $response->getBody()->write("Error updating status: " . $e->getMessage());
        return $response;
    }
});


$app->post('/user_remark', function (Request $request, Response $response) {
    try {
        $data = $request->getParsedBody();
        error_log("Received remark data: " . json_encode($data));
        
        $id = $data['id'] ?? '';
        $remark = $data['remark'] ?? '';
        $name = $data['name'] ?? '';
        
        if (empty($id) || empty($remark)) {
            $response = $response->withStatus(400);
            $response->getBody()->write("Invalid Data");
            return $response;
        }
        
        $connection = get_database_connection();
        
        if (!$connection) {
            $response = $response->withStatus(500);
            $response->getBody()->write("Connection failed");
            return $response;
        }
        
        $sql = "UPDATE accepted_issues SET remark = :remark WHERE id = :id";
        $stmt = $connection->prepare($sql);
        $stmt->bindParam(':remark', $remark);
        $stmt->bindParam(':id', $id);
        
        error_log("Executing query: $sql with id: $id and remark: $remark");
        
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $response = $response->withStatus(200);
            $response->getBody()->write("Remark submitted successfully.");
        } else {
            $response = $response->withStatus(404);
            $response->getBody()->write("No record found for the provided id");
        }
        
        $stmt->closeCursor();
        $connection = null;
        return $response;
    } catch (Exception $e) {
        error_log("Error Submitting Remark: " . $e->getMessage());
        $response = $response->withStatus(500);
        $response->getBody()->write("Error Submitting Remark: " . $e->getMessage());
        return $response;
    }
});




	
			


$app->post('/upload', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    // Check if a file is uploaded
    //$uploadedFile = $request->getUploadedFiles()['excel_file'] ?? null;

    if ($uploadedFile) {
        $fileTmpPath = $uploadedFile->getStream()->getMetadata('uri');

        // Load the Excel file using PhpSpreadsheet
        $spreadsheet = IOFactory::load($fileTmpPath);
        $worksheet = $spreadsheet->getActiveSheet();

        // Initialize an array to store Excel data
        $excelData = [];

        // Iterate through rows and store data in the array
        foreach ($worksheet->getRowIterator() as $row) {
            $rowData = [];
            foreach ($row->getCellIterator() as $cell) {
                $rowData[] = $cell->getValue();
            }
            $excelData[] = $rowData;
        }

        // Prepare and execute the SQL query to insert data into the MySQL table
        $sql = "INSERT INTO assets3 (assetName, assetModel, configuration, serialNumber, renewalDate, endOfLife, assetNumber, amount, image_filename, invoice_number, invoice_date, type, vendor, purchase_category, asset_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $connection->prepare($sql);
        foreach ($excelData as $row) {
            $stmt->execute($row);
        }

        // Close the prepared statement
        $stmt->closeCursor();

        // Close the database connection
        $connection = null;

        $response->getBody()->write("Data inserted successfully.");
    } else {
        $response->getBody()->write("Failed to upload the file.");
    }

    return $response;
});



// Route to display the upload form
$app->get('/upload_form', function (Request $request, Response $response) {
    $html = renderTemplate('upload_form.html');
    $response->getBody()->write($html);
    return $response;
});


// Route: Download Image
//if (isset($_GET['filename'])) {
//    $filename = $_GET['filename'];
//    $filepath = UPLOAD_FOLDER . '/' . $filename;
//    if (file_exists($filepath)) {
//        header('Content-Description: File Transfer');
//        header('Content-Type: application/octet-stream');
//        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
//        readfile($filepath);
//    } else {
//        http_response_code(404);
//        echo 'File not found';
//    }
//}

$app->get('/response', function (Request $request, Response $response, $args) {
	$content = renderTemplate('response.html');
	$response->getBody()->write($content);
	return $response;
});




$app->get('/assets', function (Request $request, Response $response, $args) {
    $content = renderTemplate('login-try.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/', function (Request $request, Response $response, $args) {
    $content = renderTemplate('index.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/confirmation', function (Request $request, Response $response, $args) {
    $content = renderTemplate('confirmation.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/boxes', function (Request $request, Response $response, $args) {
    $content = renderTemplate('boxes.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/dashboard', function (Request $request, Response $response, $args) {
    $content = renderTemplate('dashboard.html');
	$response->getBody()->write($content);
	return $response;
});



$app->get('/dashboard-user', function (Request $request, Response $response, $args) {
    $content = renderTemplate('dashboard-user.html');
	$response->getBody()->write($content);
	return $response;
});

$app->get('/dashboard-sbu-head', function (Request $request, Response $response, $args) {
    $content = renderTemplate('dashboard-sbu-head.html');
	$response->getBody()->write($content);
	return $response;
});

$app->get('/dashboard-sbu-head-apm', function (Request $request, Response $response, $args) {
    $content = renderTemplate('dashboard-sbu-head-apm.html');
	$response->getBody()->write($content);
	return $response;
});

$app->get('/admin-portal', function (Request $request, Response $response, $args) {
    $content = renderTemplate('admin-portal.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/request-form', function (Request $request, Response $response, $args) {
    $content = renderTemplate('request-form.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/accept_reject', function (Request $request, Response $response, $args) {
    $content = renderTemplate('accept-reject.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/return-form', function (Request $request, Response $response, $args) {
    $content = renderTemplate('return-form-trial.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/logs', function (Request $request, Response $response, $args) {
    $content = renderTemplate('logs.html');
    $response->getBody()->write($content);
    return $response;
});

$app->get('/user-portal', function (Request $request, Response $response, $args) {
    $content = renderTemplate('user-portal.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/user-portal-aravind', function (Request $request, Response $response, $args) {
    $content = renderTemplate('user-portal-aravind.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/user-portal-rafiqul', function (Request $request, Response $response, $args) {
    $content = renderTemplate('user-portal-rafiqul.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/user-portal-shashi', function (Request $request, Response $response, $args) {
    $content = renderTemplate('user-portal-shashi.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/user-portal-deepak', function (Request $request, Response $response, $args) {
    $content = renderTemplate('user-portal-deepak.html');
    $response->getBody()->write($content);
	return $response;
});


$app->get('/report', function (Request $request, Response $response, $args) {
    $content = renderTemplate('report.html');
	$response->getBody()->write($content);
	return $response;
});

	


//$app->get('/request_made', function (Request $request, Response $response, $args) {
//    $content = renderTemplate('requests.html');
//	$response->getBody()->write($content);
//	return $response;
//});

$app->get('/sbu-head-portal', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-md', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-md.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-apm', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-apm.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-ipd', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-ipd.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-ems', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-ems.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-bd', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-bd.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-ed', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-ed.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-finance', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-finance.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-pms', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-pms.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-wsd-oms', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-wsd-oms.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-trbdesign', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-trbdesign.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/sbu-head-portal-trbpmc', function (Request $request, Response $response, $args) {
    $content = renderTemplate('sbu-head-portal-trbpms.html');
    $response->getBody()->write($content);
	return $response;
});


$app->get('/add-asset', function (Request $request, Response $response, $args) {
    $content = renderTemplate('add-asset.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/logout', function ($request, $response, $args) {
    $this->get('session')->clear();
    $this->get('session')->remove('userAuthenticated');
    $this->get('session')->remove('userRole');

    $this->get('flash')->addMessage('success', 'Logged out successfully!');
    return $response->withHeader('Location', '/');
});


// Combined route to fetch all dashboard data
$app->get('/dashboard-data', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware'");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];

		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Autodesk' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ownedDesktopStmt = $connection->query("SELECT COUNT(*) as ownedDesktop FROM all_assets WHERE assetName='desktop' and purchase_category='owned' ");
        $ownedLaptopStmt = $connection->query("SELECT COUNT(*) as ownedLaptop FROM all_assets WHERE assetName='laptop' and purchase_category='owned'");
        $rentalDesktopStmt = $connection->query("SELECT COUNT(*) as rentalDesktop FROM all_assets WHERE assetName='desktop' and purchase_category='rental'");
        $rentalLaptopStmt = $connection->query("SELECT COUNT(*) as rentalLaptop FROM all_assets WHERE assetName='laptop' and purchase_category='rental'");
        $ownedDesktop = $ownedDesktopStmt->fetch(PDO::FETCH_ASSOC)['ownedDesktop'];
        $ownedLaptop = $ownedLaptopStmt->fetch(PDO::FETCH_ASSOC)['ownedLaptop'];
        $rentalDesktop = $rentalDesktopStmt->fetch(PDO::FETCH_ASSOC)['rentalDesktop'];
        $rentalLaptop = $rentalLaptopStmt->fetch(PDO::FETCH_ASSOC)['rentalLaptop'];

        // Prepare the combined response data
        $responseData = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'spareDevices' => $spareDevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions,
            'ownedDesktop' => $ownedDesktop,
            'ownedLaptop' => $ownedLaptop,
            'rentalDesktop' => $rentalDesktop,
            'rentalLaptop' => $rentalLaptop
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});





///////////////////////////////////////////////////////APM///////////////////////////////////////////////////////////




$app->get('/dashboard-data-apm', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'APM'");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'APM' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'APM'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'APM' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop' AND sbu_name = 'APM'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop' AND sbu_name = 'APM'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];
		
		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name = 'APM' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
// Fetch software options data
$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Autodesk' AND sbu_name = 'APM' GROUP BY asset_name";
$stmt = $connection->prepare($sql);
$stmt->execute();
$softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);




// Prepare the combined response data
$responseData = [
    'hardware' => [
        'totalDevices' => $totalDevices,
        'spareDevices' => $spareDevices
    ],
    'software' => [
        'totalSoftware' => $totalSoftware,
        'spareSoftware' => $spareSoftware
    ],
    'computers' => [
        'laptops' => $laptopCount,
        'desktops' => $desktopCount
    ],
	'softwareOptionsAll' => $softwareOptionsAll,
    'softwareOptions' => $softwareOptions
];


        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});



////////////////////////////////////////////////////APM-ENDDDDD//////////////////////////////////////////////////////////////////


///////////////////////////////////////////////////IPD/////////////////////////////////////////////////

$app->get('/dashboard-data-ipd', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'IPD'");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'IPD' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'IPD'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'IPD' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop' AND sbu_name = 'IPD'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop' AND sbu_name = 'IPD'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];
		
		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name = 'IPD' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Autodesk' AND sbu_name = 'IPD' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the combined response data
        $responseData = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'spareDevices' => $spareDevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});



/////////////////////////////////////////////////////////////ems////////////////////////////////////////////////////////

$app->get('/dashboard-data-ems', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'EMS'");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'EMS' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'EMS'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'EMS' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop' AND sbu_name = 'EMS'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop' AND sbu_name = 'EMS'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];
		
		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name = 'EMS' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Autodesk' AND sbu_name = 'EMS' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the combined response data
        $responseData = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'spareDevices' => $spareDevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});

////////////////////////////////////////////////////////////trbdesign///////////////////////////////////////////////////

$app->get('/dashboard-data-trbdesign', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'TRB(Design)'");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'TRB(Design)' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'TRB(Design)'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'TRB(Design)' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop' AND sbu_name = 'TRB(Design)'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop' AND sbu_name = 'TRB(Design)'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];
		
		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name = 'TRB(Design)' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Autodesk' AND sbu_name = 'TRB(Design)' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the combined response data
        $responseData = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'spareDevices' => $spareDevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});

////////////////////////////////////////////////////////////////////////////trbpmc////////////////////////////////////////

$app->get('/dashboard-data-trbpmc', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'TRB(PMC)'");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'TRB(PMC)' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'TRB(PMC)'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'TRB(PMC)' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop' AND sbu_name = 'TRB(PMC)'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop' AND sbu_name = 'TRB(PMC)'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];

		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name = 'TRB(PMC)' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Autodesk' AND sbu_name = 'TRB(PMC)' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the combined response data
        $responseData = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'spareDevices' => $spareDevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});



///////////////////////////////////////////////////////////////////wsd-oms/////////////////////////////////////////////////

$app->get('/dashboard-data-wsd-oms', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name IN ('wsd', 'oms')");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name IN ('wsd', 'oms') AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name IN ('wsd', 'oms')");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name IN ('wsd', 'oms') AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop' AND sbu_name IN ('wsd', 'oms')");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop' AND sbu_name IN ('wsd', 'oms')");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];

		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name IN ('wsd', 'oms') GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Autodesk' AND sbu_name IN ('wsd', 'oms') GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the combined response data
        $responseData = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'spareDevices' => $spareDevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});


/////////////////////////////////////////////////////////////////finance//////////////////////////////////////////////////////////////

$app->get('/dashboard-data-finance', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM acceptfinance_issues WHERE type = 'hardware' AND sbu_name = 'finance'");
        $sparfinanceevicesStmt = $connection->query("SELECT COUNT(*) as sparfinanceevices FROM acceptfinance_issues WHERE type = 'hardware' AND sbu_name = 'finance' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $sparfinanceevices = $sparfinanceevicesStmt->fetch(PDO::FETCH_ASSOC)['sparfinanceevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM acceptfinance_issues WHERE type = 'software' AND sbu_name = 'finance'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM acceptfinance_issues WHERE type = 'software' AND sbu_name = 'finance' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM acceptfinance_issues WHERE asset_name = 'laptop' AND sbu_name = 'finance'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM acceptfinance_issues WHERE asset_name = 'desktop' AND sbu_name = 'finance'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];

		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name = 'Finance' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM acceptfinance_issues WHERE type = 'software' AND asset_model = 'Adobe' AND sbu_name = 'finance' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the combinfinance response data
        $responsfinanceata = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'sparfinanceevices' => $sparfinanceevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responsfinanceata);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if nefinancefinance
        if ($connection) {
            $connection = null;
        }
    }
});


///////////////////////////////////////////////////////////////////ed//////////////////////////////////////////////////////////////


$app->get('/dashboard-data-ed', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'ed'");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'ed' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'ed'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'ed' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop' AND sbu_name = 'ed'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop' AND sbu_name = 'ed'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];

		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name = 'ED' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Autodesk' AND sbu_name = 'ed' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the combined response data
        $responseData = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'spareDevices' => $spareDevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});


///////////////////////////////////////////////////////////////////////bd/////////////////////////////////////////////////////////

$app->get('/dashboard-data-bd', function (Request $request, Response $response) {
    // Get the database connection
    $connection = get_database_connection();

    try {
        // Fetch hardware data
        $totalDevicesStmt = $connection->query("SELECT COUNT(*) as totalDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'bd'");
        $spareDevicesStmt = $connection->query("SELECT COUNT(*) as spareDevices FROM accepted_issues WHERE type = 'hardware' AND sbu_name = 'bd' AND employee_name = 'spare'");
        $totalDevices = $totalDevicesStmt->fetch(PDO::FETCH_ASSOC)['totalDevices'];
        $spareDevices = $spareDevicesStmt->fetch(PDO::FETCH_ASSOC)['spareDevices'];

        // Fetch software data
        $totalSoftwareStmt = $connection->query("SELECT COUNT(*) as totalSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'bd'");
        $spareSoftwareStmt = $connection->query("SELECT COUNT(*) as spareSoftware FROM accepted_issues WHERE type = 'software' AND sbu_name = 'bd' AND employee_name = 'spare'");
        $totalSoftware = $totalSoftwareStmt->fetch(PDO::FETCH_ASSOC)['totalSoftware'];
        $spareSoftware = $spareSoftwareStmt->fetch(PDO::FETCH_ASSOC)['spareSoftware'];

        // Fetch computer data
        $laptopStmt = $connection->query("SELECT COUNT(*) as laptopCount FROM accepted_issues WHERE asset_name = 'laptop' AND sbu_name = 'bd'");
        $desktopStmt = $connection->query("SELECT COUNT(*) as desktopCount FROM accepted_issues WHERE asset_name = 'desktop' AND sbu_name = 'bd'");
        $laptopCount = $laptopStmt->fetch(PDO::FETCH_ASSOC)['laptopCount'];
        $desktopCount = $desktopStmt->fetch(PDO::FETCH_ASSOC)['desktopCount'];

		$sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND sbu_name = 'bd' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptionsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch software options data
        $sql = "SELECT asset_name, COUNT(*) AS quantity FROM accepted_issues WHERE type = 'software' AND asset_model = 'Adobe' AND sbu_name = 'bd' GROUP BY asset_name";
        $stmt = $connection->prepare($sql);
        $stmt->execute();
        $softwareOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the combined response data
        $responseData = [
            'hardware' => [
                'totalDevices' => $totalDevices,
                'spareDevices' => $spareDevices
            ],
            'software' => [
                'totalSoftware' => $totalSoftware,
                'spareSoftware' => $spareSoftware
            ],
            'computers' => [
                'laptops' => $laptopCount,
                'desktops' => $desktopCount
            ],
			'softwareOptionsAll' => $softwareOptionsAll,
            'softwareOptions' => $softwareOptions
        ];

        // Convert the response data to JSON
        $jsonResponse = json_encode($responseData);

        // Set the JSON response
        $response->getBody()->write($jsonResponse);
        $response = $response->withHeader('Content-Type', 'application/json');

        return $response;
    } catch (PDOException $e) {
        // Handle database error
        $response->getBody()->write(json_encode(['error' => 'Database error: ' . $e->getMessage()]));

        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

// Define routes

$app->get('/all-assets', function (Request $request, Response $response, $args) {
    $content = renderTemplate('all-assets.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/available-assets', function (Request $request, Response $response, $args) {
    $content = renderTemplate('available-assets.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/all-locations', function ($request, $response, $args) {
    return $this->get('renderer')->render($response, 'all-locations.html');
});

$app->get('/all-departments', function ($request, $response, $args) {
    return $this->get('renderer')->render($response, 'all-departments.html');
});

$app->get('/accepted-issues', function (Request $request, Response $response, $args) {
    $content = renderTemplate('accepted-issues.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/accepted-issues-sbu', function (Request $request, Response $response, $args) {
    $content = renderTemplate('accepted-issues-sbu.html');
    $response->getBody()->write($content);
	return $response;
});

$app->get('/owned-assets', function (Request $request, Response $response, $args){
    try{
        $connection = get_database_connection();
        $cursor = $connection->query("SELECT * FROM all_assets WHERE assetName = 'LAPTOP' AND purchase_category = 'Owned'");
        $rows = $cursor->fetchAll();
        ob_start();
        include 'templates/dashboard.html';
        $content = ob_get_clean();
        $response->getBody()->write($content);

        return $response;
    } catch (Exception $e) {
        // Handle exceptions, such as database connection error
        return $response->withStatus(500)->write("Error: " . $e->getMessage());
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});

$app->get('/accepted-issues-apm', function (Request $request, Response $response, $args) {
    try {
        // Assuming your database connection is already configured elsewhere
        
        // Perform database query to fetch accepted issues for APM
        $connection = get_database_connection(); // Assuming this function is defined elsewhere
        $cursor = $connection->query("SELECT * FROM accepted_issues WHERE sbu_name = 'APM'");
        $rows = $cursor->fetchAll();

        // Render the template with fetched data
        ob_start();
        include 'templates/accepted-issues-apm.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);

        return $response;
    } catch (Exception $e) {
        // Handle exceptions, such as database connection error
        return $response->withStatus(500)->write("Error: " . $e->getMessage());
    } finally {
        // Close database connection if needed
        if ($connection) {
            $connection = null;
        }
    }
});



$app->get('/accepted-issues-pms', function ($request, $response, $args) {
    try {
        $connection = get_database_connection();
        $cursor = $connection->query("SELECT * FROM accepted_issues WHERE sbu_name = 'PMS'");
        $rows = $cursor->fetchAll();

        return $this->get('renderer')->render($response, 'accepted-issues-pms.html', ['data' => $rows]);
    } catch (Exception $e) {
        return $response->write($e->getMessage());
    }
});

$app->get('/accepted-issues-wsd-oms', function ($request, $response, $args) {
    try {
        $connection = get_database_connection();
        $cursor = $connection->query("SELECT * FROM accepted_issues WHERE sbu_name = 'WSD' OR sbu_name = 'OMS'");
        $rows = $cursor->fetchAll();

        return $this->get('renderer')->render($response, 'accepted-issues-wsd-oms.html', ['data' => $rows]);
    } catch (Exception $e) {
        return $response->write($e->getMessage());
    }
});

// Define other routes similarly...

// Define the flash function
function flash($message, $type = 'info') {
    if (!isset($_SESSION)) {
        session_start();
    }
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}




$app->post('/add-asset', function (Request $request, Response $response) use ($app) {
    try {
        // Handle asset data
        $parsedBody = $request->getParsedBody();
        $assetName = $parsedBody['assetName'] ?? null;
        $type = $parsedBody['type'] ?? null;
        $assetModel = $parsedBody['assetModel'] ?? null;
        $configuration = $parsedBody['configuration'] ?? null;
        $serialNumber = $parsedBody['serialNumber'] ?? null;
        $renewalDate = $parsedBody['renewalDate'] ?? null;
        $endOfLife = $parsedBody['endOfLife'] ?? null;
        $assetNumber = $parsedBody['assetNumber'] ?? null;
        $amount = $parsedBody['amount'] ?? null;
        $invoice_number = $parsedBody['invoice_number'] ?? null;
        $invoice_date = $parsedBody['invoice_date'] ?? null;
        $purchase_category = $parsedBody['purchase_category'] ?? null;
        $vendor = $parsedBody['vendor'] ?? null;
        $asset_location = $parsedBody['asset_location'] ?? null;

        // Handle image upload
        $image = $request->getUploadedFiles()['image'] ?? null;
        $image_filename = null;
        if ($image && $image->getError() === UPLOAD_ERR_OK) {
            $image_filename = $image->getClientFilename();
            $image->moveTo(UPLOAD_FOLDER . '/' . $image_filename);
        }
      
        // Connect to MySQL database
        $connection = get_database_connection();
        $query = "INSERT INTO assets3 (type, assetNumber, assetName, assetModel, serialNumber, configuration, amount, renewalDate, endOfLife, image_filename, invoice_number, invoice_date, purchase_category, vendor, asset_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $statement = $connection->prepare($query);
        $statement->execute([$type, $assetNumber, $assetName, $assetModel, $serialNumber, $configuration, $amount, $renewalDate, $endOfLife, $image_filename, $invoice_number, $invoice_date, $purchase_category, $vendor, $asset_location]);

        $connection = null;

        // Redirect with success message
        return $response->withHeader('Location', '/add-asset')->withStatus(302);
    } catch (Exception $e) {
        error_log("Error adding asset: " . $e->getMessage());
        // Redirect with error message
        return $response->withHeader('Location', '/add-asset')->withStatus(302);
    }
});



// Function to fetch assets from the database
// Function to fetch assets from the database
function get_assets() {
    try {
        // Connect to the database
        $connection = get_database_connection();
        
        // Prepare and execute the query
        $query = "SELECT assetId, assetName, assetModel, configuration, serialNumber, renewalDate, endOfLife, assetNumber, amount, image_filename, invoice_number, invoice_date, type, purchase_category, vendor, asset_location FROM assets3";
        $statement = $connection->prepare($query);
        $statement->execute();
        
        // Fetch all rows
        $data = $statement->fetchAll(PDO::FETCH_ASSOC); // Specify fetch mode to fetch associative arrays

        // Close the database connection
        $connection = null;

        // Return the fetched data
        return $data;
    } catch (PDOException $e) {
        // Handle any exceptions
        echo "Error fetching assets data: " . $e->getMessage();
        return []; // Return an empty array in case of an error
    }
}

// Route to get all logs from the 'logs' table
$app->get('/get_log', function (Request $request, Response $response) {
    try {
        // Establish the database connection
        $connection = get_database_connection();
        $cursor = $connection->query("SELECT * FROM logs");
        $data = $cursor->fetchAll();

        // Prepare the data for JSON response
        $logs = [];
        foreach ($data as $row) {
            $log = [
                'sbu_name' => $row['sbu_name'],
                'employee_code' => $row['employee_code'],
                'employee_name' => $row['employee_name'],
                'asset_name' => $row['asset_name'],
                'asset_model' => $row['asset_model'],
                'serial_number' => $row['serial_number'],
                'asset_id' => $row['asset_id'],
                'description' => $row['description'],
                'location' => $row['location'],
                'image_filename' => $row['image_filename'],
                'type' => $row['type'],
                'issue_date' => $row['issue_date'],
                'return_date' => $row['return_date'],
            ];
            $logs[] = $log;
        }

        // Close the cursor and connection
        $responseBody = json_encode($logs);
        $response->getBody()->write($responseBody);

        return $response->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        // Handle the exception here (e.g., log the error, return an error response)
        return $response->withStatus(500)->withJson(['error' => 'Error fetching logs data']);
    }
});

$app->post('/update_image', function (Request $request) use ($app) {
    try {
        $asset_id = $request->request->get('assetId');
        $image_file = $request->files->get('imageFile');

        if ($image_file) {
            $image_filename = $image_file->getClientOriginalName();
            $image_path = 'static/images/' . $image_filename;
            $image_file->move('static/images/', $image_filename);

            $connection = get_database_connection();
            $query = "UPDATE all_assets SET image_filename = ? WHERE assetId = ?";
            $statement = $connection->prepare($query);
            $statement->execute([$image_filename, $asset_id]);

            $connection = null;

            $response_data = [
                'success' => true,
                'newImageFilename' => $image_filename
            ];
        } else {
            $response_data = [
                'success' => false,
                'error' => 'No image file received.'
            ];
        }

        return new Response(json_encode($response_data), 200, ['Content-Type' => 'application/json']);
    } catch (Exception $e) {
        $response_data = [
            'success' => false,
            'error' => $e->getMessage()
        ];
        return new Response(json_encode($response_data), 500, ['Content-Type' => 'application/json']);
    }
});

$app->post('/update_asset_image', function (Request $request, Response $response) use ($app) {
    try {
        $asset_id = $request->getParsedBody()['assetId'] ?? null;
        $uploadedFile = $request->getUploadedFiles()['imageFile'] ?? null;

        if ($uploadedFile) {
            $image_filename = $uploadedFile->getClientFilename();
            $image_path = 'static/images/' . $image_filename;

            // Move the uploaded file to the desired location
            $uploadedFile->moveTo($image_path);

            // Update the image filename in both tables
            $connection = get_database_connection();

            // Update image filename in assets3 table
            $query_assets3 = "UPDATE assets3 SET image_filename = ? WHERE assetId = ?";
            $statement_assets3 = $connection->prepare($query_assets3);
            $statement_assets3->execute([$image_filename, $asset_id]);

            // Update image filename in all_assets table
            $query_all_assets = "UPDATE all_assets SET image_filename = ? WHERE assetId = ?";
            $statement_all_assets = $connection->prepare($query_all_assets);
            $statement_all_assets->execute([$image_filename, $asset_id]);

            $connection = null;

            $response_data = [
                'success' => true,
                'newImageFilename' => $image_filename
            ];
        } else {
            $response_data = [
                'success' => false,
                'error' => 'No image file received.'
            ];
        }

        // Send JSON response
        $response->getBody()->write(json_encode($response_data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    } catch (Exception $e) {
        $response_data = [
            'success' => false,
            'error' => $e->getMessage()
        ];
        // Send JSON response with error status
        $response->getBody()->write(json_encode($response_data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});



// Route to get all assets from the 'all_assets' table
$app->get('/get_all_assets', function (Request $request, Response $response) {
    try {
        $connection = get_database_connection();
        $cursor = $connection->query("SELECT assetId, assetName, assetModel, serialNumber, configuration,
                                            DATE_FORMAT(renewalDate, '%Y-%m-%d') AS renewalDate,
                                            DATE_FORMAT(endOfLife, '%Y-%m-%d') AS endOfLife,
                                            assetNumber, amount, image_filename, invoice_number,
                                            DATE_FORMAT(invoice_date, '%Y-%m-%d') AS invoice_date,
                                            type, vendor, purchase_category, asset_location
                                            FROM all_assets");
        $data = $cursor->fetchAll();

        $assets_list = [];
        foreach ($data as $row) {
            $asset_dict = [
                'assetId' => $row['assetId'],
                'assetName' => $row['assetName'],
                'assetModel' => $row['assetModel'],
                'serialNumber' => $row['serialNumber'],
                'configuration' => $row['configuration'],
                'renewalDate' => $row['renewalDate'],
                'endOfLife' => $row['endOfLife'],
                'assetNumber' => $row['assetNumber'],
                'amount' => $row['amount'],
                'image_filename' => $row['image_filename'],
                'invoice_number' => $row['invoice_number'],
                'invoice_date' => $row['invoice_date'],
                'type' => $row['type'],
                'vendor' => $row['vendor'],
                'purchase_category' => $row['purchase_category'],
                'asset_location' => $row['asset_location']
            ];
            $assets_list[] = $asset_dict;
        }

        $responseBody = json_encode($assets_list);
        $response->getBody()->write($responseBody);

        return $response->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json')->getBody()->write(json_encode(['error' => 'Error fetching data']));
    }
});


// Update the route to manually create a JSON response
$app->get('/get_assets3', function (Request $request, Response $response, $args) {
    try {
        // Connect to the MySQL database
        $connection = get_database_connection();
        $cursor = $connection->query("SELECT assetId, assetName, assetModel, serialNumber, configuration,
                                            DATE_FORMAT(renewalDate, '%Y-%m-%d') AS renewalDate,
                                            DATE_FORMAT(endOfLife, '%Y-%m-%d') AS endOfLife,
                                            assetNumber, amount, image_filename, invoice_number,
                                            DATE_FORMAT(invoice_date, '%Y-%m-%d') AS invoice_date,
                                            type, vendor, purchase_category, asset_location
                                            FROM assets3");
        $data = $cursor->fetchAll();

        // Manually create a JSON response
        $responseBody = json_encode($data);
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write($responseBody);

        return $response;

    } catch (Exception $e) {
        // Handle errors and return an error response
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json')->getBody()->write(json_encode(['error' => 'Error fetching data']));
    }
});







// Route to get accepted issues with optional filters
$app->get('/get_accepted_issues', function (Request $request, Response $response) {
    try {
        $connection = get_database_connection();

        // Fetch the SBU Name and Location filters from the request arguments
        $sbu_name = $request->getQueryParams()['sbu_name'] ?? null;
        $location = $request->getQueryParams()['location'] ?? null;

        // Define the base query
        $select_query = "SELECT * FROM accepted_issues WHERE 1";

        // Prepare the query parameters
        $query_params = [];

        // Add the SBU Name filter if provided
        if ($sbu_name) {
            $select_query .= " AND sbu_name = ?";
            $query_params[] = $sbu_name;
        }

        // Add the Location filter if provided
        if ($location) {
            $select_query .= " AND location = ?";
            $query_params[] = $location;
        }

        // Execute the query with the parameters
        $cursor = $connection->prepare($select_query);
        $cursor->execute($query_params);
        $data = $cursor->fetchAll();

        $accepted_issues = [];
        foreach ($data as $row) {
            $issue = [
                'sbu_name' => $row['sbu_name'],
                'employee_code' => $row['employee_code'],
                'employee_name' => $row['employee_name'],
                'asset_name' => $row['asset_name'],
                'asset_model' => $row['asset_model'],
                'serial_number' => $row['serial_number'],
                'asset_id' => $row['asset_id'],
                'description' => $row['description'],
                'issue_date' => $row['issue_date'],
                'location' => $row['location'],
                'image_filename' => $row['image_filename'],
                'type' => $row['type'],
				'email' => $row['email'],
				'status' => $row['status'],
				'confirmed_at' => $row['confirmed_at']
            ];
            $accepted_issues[] = $issue;
        }

        $responseBody = json_encode($accepted_issues);
        $response->getBody()->write($responseBody);

        return $response->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json')->getBody()->write(json_encode(['error' => $e->getMessage()]));
    }
});




$app->post('/add-department', function (Request $request, Response $response) use ($app) {
    if ($request->getMethod() === 'POST') {
        $departmentId = $request->getParsedBody()['departmentId'] ?? '';
        $departmentName = $request->getParsedBody()['departmentName'] ?? '';
        $departmentHead = $request->getParsedBody()['departmentHead'] ?? '';
        $description = $request->getParsedBody()['description'] ?? '';

        // Connect to MySQL database
        $connection = get_database_connection();
        $query = "INSERT INTO department (departmentId, departmentName, departmentHead, description) VALUES (?, ?, ?, ?)";
        $statement = $connection->prepare($query);
        $statement->execute([$departmentId, $departmentName, $departmentHead, $description]);

        $connection = null;

        return $response->getBody()->write("Department added successfully!");
    }

    return $app->get('view')->render($response, 'add-department.html');
});

// Route to get departments
$app->get('/get_departments', function (Request $request) use ($app) {
    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $query = "SELECT * FROM department";
        $statement = $connection->prepare($query);
        $statement->execute();
        $data = $statement->fetchAll();

        $departments = [];
        foreach ($data as $row) {
            $department = [
                'departmentId' => $row['departmentId'],
                'departmentName' => $row['departmentName'],
                'departmentHead' => $row['departmentHead'],
                'description' => $row['description']
            ];
            $departments[] = $department;
        }

        $connection = null;

        return new Response(json_encode($departments), 200, ['Content-Type' => 'application/json']);
    } catch (Exception $e) {
        return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
    }
});

// Route to delete asset
$app->post('/delete_asset/{asset_id}', function ($asset_id) use ($app) {
    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $query = "DELETE FROM assets3 WHERE assetId = ?";
        $statement = $connection->prepare($query);
        $statement->execute([$asset_id]);

        $connection = null;

        return new Response(json_encode(['message' => 'Asset deleted successfully']), 200, ['Content-Type' => 'application/json']);
    } catch (Exception $e) {
        return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
    }
});

// Route to delete department
$app->post('/delete_department/{departmentId}', function ($departmentId) use ($app) {
    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $query = "DELETE FROM department WHERE departmentId = ?";
        $statement = $connection->prepare($query);
        $statement->execute([$departmentId]);

        $connection = null;

        return "Department deleted successfully!";
    } catch (Exception $e) {
        return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
    }
});


// API endpoint to get asset types
$app->get('/api/get_asset_types', function (Request $request, Response $response, array $args) {
    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $statement = $connection->prepare('SELECT DISTINCT type FROM assets3');
        $statement->execute();
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the data for JSON response
        $asset_types = array_column($data, 'type');

        // Close the connection
        $connection = null;

        $response->getBody()->write(json_encode($asset_types));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (PDOException $e) {
        return $response->withJson(['error' => $e->getMessage()], 500);
    }
});

// API endpoint to get asset names
$app->get('/api/get_asset_names', function (Request $request, Response $response, array $args) {
    $asset_type = $request->getQueryParams()['asset_type'] ?? null;
    if (!$asset_type) {
        return $response->withJson(['error' => 'Asset type is required'], 400);
    }

    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $statement = $connection->prepare('SELECT DISTINCT assetName FROM assets3 WHERE type = ?');
        $statement->execute([$asset_type]);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the data for JSON response
        $asset_names = array_column($data, 'assetName');

        // Close the connection
        $connection = null;

        $response->getBody()->write(json_encode($asset_names));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (PDOException $e) {
        return $response->withJson(['error' => $e->getMessage()], 500);
    }
});

// API endpoint to get asset models
$app->get('/api/get_asset_models', function (Request $request, Response $response, array $args) {
    $asset_type = $request->getQueryParams()['asset_type'] ?? null;
    $asset_name = $request->getQueryParams()['asset_name'] ?? null;
    if (!$asset_type || !$asset_name) {
        return $response->withJson(['error' => 'Asset type and asset name are required'], 400);
    }

    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $statement = $connection->prepare('SELECT DISTINCT assetModel FROM assets3 WHERE type = ? AND assetName = ?');
        $statement->execute([$asset_type, $asset_name]);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the data for JSON response
        $asset_models = array_column($data, 'assetModel');

        // Close the connection
        $connection = null;

        $response->getBody()->write(json_encode($asset_models));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (PDOException $e) {
        return $response->withJson(['error' => $e->getMessage()], 500);
    }
});

// API endpoint to get serial numbers
$app->get('/api/get_serial_numbers', function (Request $request, Response $response, array $args) {
    $asset_type = $request->getQueryParams()['asset_type'] ?? null;
    $asset_name = $request->getQueryParams()['asset_name'] ?? null;
    $asset_model = $request->getQueryParams()['asset_model'] ?? null;
    if (!$asset_type || !$asset_name || !$asset_model) {
        return $response->withJson(['error' => 'Asset type, asset name, and asset model are required'], 400);
    }

    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $statement = $connection->prepare('SELECT DISTINCT serialNumber FROM assets3 WHERE type = ? AND assetName = ? AND assetModel = ?');
        $statement->execute([$asset_type, $asset_name, $asset_model]);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the data for JSON response
        $serial_numbers = array_column($data, 'serialNumber');

        // Close the connection
        $connection = null;

        $response->getBody()->write(json_encode($serial_numbers));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (PDOException $e) {
        return $response->withJson(['error' => $e->getMessage()], 500);
    }
});

// API endpoint to get asset ids
$app->get('/api/get_asset_ids', function (Request $request, Response $response, array $args) {
    $asset_type = $request->getQueryParams()['asset_type'] ?? null;
    $asset_name = $request->getQueryParams()['asset_name'] ?? null;
    $asset_model = $request->getQueryParams()['asset_model'] ?? null;
    $serial_number = $request->getQueryParams()['serial_number'] ?? null;
    if (!$asset_type || !$asset_name || !$asset_model || !$serial_number) {
        return $response->withJson(['error' => 'Asset type, asset name, asset model, and serial number are required'], 400);
    }

    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $statement = $connection->prepare('SELECT DISTINCT assetId FROM assets3 WHERE type = ? AND assetName = ? AND assetModel = ? AND serialNumber = ?');
        $statement->execute([$asset_type, $asset_name, $asset_model, $serial_number]);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Prepare the data for JSON response
        $asset_ids = array_column($data, 'assetId');

        // Close the connection
        $connection = null;

        $response->getBody()->write(json_encode($asset_ids));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (PDOException $e) {
        return $response->withJson(['error' => $e->getMessage()], 500);
    }
});



// Route to add location
$app->post('/add-location', function (Request $request) use ($app) {
    if ($request->isMethod('POST')) {
        $locationId = $request->request->get('locationId');
        $locationName = $request->request->get('locationName');
        $description = $request->request->get('description');

        // Connect to MySQL database
        $connection = get_database_connection();
        $query = "INSERT INTO location (locationId, locationName, description) VALUES (?, ?, ?)";
        $statement = $connection->prepare($query);
        $statement->execute([$locationId, $locationName, $description]);

        $connection = null;

        return "Location added successfully!";
    }

    return $app['twig']->render('add-location.html');
});

		

// Route to get locations
$app->get('/get_locations', function (Request $request) use ($app) {
    try {
        // Connect to MySQL database
        $connection = get_database_connection();
        $query = "SELECT * FROM location";
        $statement = $connection->prepare($query);
        $statement->execute();
        $data = $statement->fetchAll();

        $locations = [];
        foreach ($data as $row) {
            $location = [
                'locationId' => $row['locationId'],
                'locationName' => $row['locationName'],
                'description' => $row['description']
            ];
            $locations[] = $location;
        }

        $connection = null;

        return new Response(json_encode($locations), 200, ['Content-Type' => 'application/json']);
    } catch (Exception $e) {
        return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
    }
});

$app->get('/issue-form', function (Request $request, Response $response, $args) {
    $content = renderTemplate('issue-form.html');
	$response->getBody()->write($content);
	return $response;
});

// Route to handle issue form submission
$app->post('/handle_issue_form', function (Request $request, Response $response) use ($app) {
    $parsedBody = $request->getParsedBody();

    if ($request->getMethod() === 'POST') {
        $sbu_name = $parsedBody['sbu_name'] ?? null;
        $employee_code = $parsedBody['employee_code'] ?? null;
        $employee_name = $parsedBody['employee_name'] ?? null;
        $asset_name = $parsedBody['asset_name'] ?? null;
        $asset_model = $parsedBody['asset_model'] ?? null;
        $serial_number = $parsedBody['serial_number'] ?? null;
        $asset_id = $parsedBody['asset_id'] ?? null;
        $description = $parsedBody['description'] ?? null;
        $issue_date = $parsedBody['issue_date'] ?? null;
        $location = $parsedBody['location'] ?? null;
        $asset_type = $parsedBody['asset_type'] ?? null;
		$email = $parsedBody['email'] ?? null;

// Handle image upload
$image = $request->getUploadedFiles()['image'] ?? null;
$image_filename = null;
if ($image && $image->getError() === UPLOAD_ERR_OK) {
    // Check file type
    $allowedTypes = ['image/jpeg', 'image/png'];
    if (in_array($image->getClientMediaType(), $allowedTypes)) {
        // Check file size
        if ($image->getSize() <= 5 * 1024 * 1024) { // 5 MB in bytes
            $image_filename = $image->getClientFilename();
            $image->moveTo(UPLOAD_FOLDER . '/' . $image_filename);
        } else {
            // File size exceeds the limit
            $response->getBody()->write(json_encode(['message' => 'File size exceeds the limit of 5 MB!', 'success' => false]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    } else {
        // Invalid file type
        $response->getBody()->write(json_encode(['message' => 'Invalid file type. Only JPEG and PNG formats are allowed!', 'success' => false]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
}
 


        try {
            // Establish a connection to the MySQL database
            $connection = get_database_connection();
            $query = "INSERT INTO issued_assets (sbu_name, employee_code, employee_name, asset_name, asset_model, serial_number, asset_id, description, issue_date, location, image_filename, type, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $statement = $connection->prepare($query);
            $statement->execute([$sbu_name, $employee_code, $employee_name, $asset_name, $asset_model, $serial_number, $asset_id, $description, $issue_date, $location, $image_filename, $asset_type, $email]);
            $connection = null;

            // Create a JSON response manually
            $response->getBody()->write(json_encode(['message' => 'Form submitted successfully!', 'success' => true]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (Exception $e) {
            // Create a JSON response manually
            $response->getBody()->write(json_encode(['message' => $e->getMessage(), 'success' => false]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    // Create a JSON response manually
    $response->getBody()->write(json_encode(['message' => 'Invalid request!', 'success' => false]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
});



// Route to handle return form submission
$app->post('/handle_return_form', function (Request $request, Response $response) use ($app) {
    // Get JSON data from the request body
    $jsonBody = $request->getBody()->getContents();
    $data = json_decode($jsonBody, true);

    // Extract form fields
    $detail = $data['detail'] ?? null;
    $value = $data['value'] ?? null;
    $return_date = $data['return_date'] ?? null;

    // Handle database operations
    try {
		
		//error_log("Parsed Body: " . json_encode($parsedBody));
		
        // Establish a connection to the MySQL database
        $connection = get_database_connection();

        // Define column mapping
        $column_mapping = [
            'assetId' => 'assetId',
            'assetNumber' => 'assetNumber',
            'serialNumber' => 'serialNumber'
        ];
		
		//error_log("Column Mapping: " . json_encode($column_mapping));
		
        $column_to_copy = $column_mapping[$detail] ?? null;
		
		//error_log("Column to Copy: " . $column_to_copy);

        if ($column_to_copy === null) {
            throw new Exception('Invalid detail');
        }


        // Query to find the asset based on the selected detail
        $query = "SELECT * FROM all_assets WHERE $column_to_copy = ?";
        $statement = $connection->prepare($query);
        $statement->execute([$value]);
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            throw new Exception('No matching record found');
        }

        // Copy data from accepted_issues to logs
        $queryCopy = "INSERT INTO logs (sbu_name, employee_code, employee_name, asset_name, asset_model, serial_number, asset_id, description, location, image_filename, type, return_date) "
                   . "SELECT sbu_name, employee_code, employee_name, asset_name, asset_model, serial_number, asset_id, description, location, image_filename, type, ? "
                   . "FROM accepted_issues WHERE asset_id = ?";
        $statementCopy = $connection->prepare($queryCopy);
        $statementCopy->execute([$return_date, $result['assetId']]);

        // Delete the issued asset from accepted_issues based on asset_id
        $queryDelete = "DELETE FROM accepted_issues WHERE asset_id = ?";
        $statementDelete = $connection->prepare($queryDelete);
        $statementDelete->execute([$result['assetId']]);

        // Insert the entire row into assets3
        $queryInsert = "INSERT INTO assets3 (assetId, assetName, assetModel, serialNumber, configuration, renewalDate, endOfLife, assetNumber, amount, image_filename, invoice_number, invoice_date, type, vendor, purchase_category, asset_location) "
                     . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $statementInsert = $connection->prepare($queryInsert);
        $statementInsert->execute([
            $result['assetId'], $result['assetName'], $result['assetModel'], $result['serialNumber'],
            $result['configuration'], $result['renewalDate'], $result['endOfLife'], $result['assetNumber'],
            $result['amount'], $result['image_filename'], $result['invoice_number'], $result['invoice_date'],
            $result['type'], $result['vendor'], $result['purchase_category'], $result['asset_location']
        ]);

        // Close database connection
        $connection = null;

        // Return success response
        $successResponse = [
            'success' => true
        ];


        $response->getBody()->write(json_encode($successResponse));
        return $response->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        // Return error response
        $errorResponse = [
            'success' => false,
            'error' => $e->getMessage()
        ];

        $response->getBody()->write(json_encode($errorResponse));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});


	




// Add a new route to fetch software names
$app->get('/get_software_names', function ($request, $response, $args) use ($app) {
    try {
        // Establish a connection to the MySQL database
        $connection = get_database_connection();

        // Fetch software names from the 'software_options' table
        $query = "SELECT software_name FROM software_options";
        $statement = $connection->prepare($query);
        $statement->execute();
        $software_names = $statement->fetchAll(PDO::FETCH_ASSOC);
        $connection = null;

        // Encode software names array as JSON
        $json_data = json_encode($software_names);

        // Set response headers and body
        $response = $response->withHeader('Content-Type', 'application/json');
        $response->getBody()->write($json_data);

        // Return the response
        return $response;
    } catch (Exception $e) {
        // Return error response in case of an exception
        return $response->withJson(['error' => $e->getMessage()], 500);
    }
});



// Route to get issued assets
$app->get('/api/get_issued_assets', function () use ($app) {
    try {
        // Establish a connection to the MySQL database
        $connection = get_database_connection();

        // Fetch issued assets from the 'issued_assets' table
        $query = "SELECT * FROM issued_assets";
        $statement = $connection->prepare($query);
        $statement->execute();
        $issued_assets = $statement->fetchAll(PDO::FETCH_ASSOC);
        $connection = null;

        // Return JSON response with issued assets
        return new Response(json_encode($issued_assets), 200, ['Content-Type' => 'application/json']);
    } catch (Exception $e) {
        // Return error response in case of an exception
        return new Response(json_encode(['error' => $e->getMessage()]), 500, ['Content-Type' => 'application/json']);
    }
});

// Route to get accept/reject data
$app->get('/accept_reject_data', function (Request $request, Response $response) {
    try {
        // Establish a connection to the MySQL database
        $connection = get_database_connection();

        // Fetch accept/reject data from the corresponding table
        // Modify the query according to your database schema and requirements
        $query = "SELECT * FROM issued_assets";
        $statement = $connection->prepare($query);
        $statement->execute();
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);
        $connection = null;

        // Return JSON response with accept/reject data
        $responseBody = json_encode($data);
        $response->getBody()->write($responseBody);

        return $response->withHeader('Content-Type', 'application/json');

    } catch (Exception $e) {
        // Return error response in case of an exception
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json')->getBody()->write(json_encode(['error' => $e->getMessage()]));
    }
});

$app->get('/requests_made', function ($request, $response, $args) use ($app) {
    try {
        // Establish a connection to the MySQL database
        $connection = get_database_connection();
        $statement = $connection->prepare('SELECT id, sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status FROM requests');
        $statement->execute();
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);
        
        // Pass the data to the template for rendering
        ob_start();
        include 'templates/requests.html';
        $content = ob_get_clean();

        // Write the response content
        $response->getBody()->write($content);

        // Return the response with appropriate headers
        return $response->withHeader('Content-Type', 'text/html');

   } catch (PDOException $e) {
        // Handle any database errors
        error_log("Error fetching requests data: " . $e->getMessage());
        // Render the template with an empty array in case of an error
        ob_start();
        include 'templates/requests.html';
        $content = ob_get_clean();
        $response->getBody()->write($content);
        return $response->withHeader('Content-Type', 'text/html');
    } finally {
        // Close the database connection
        $connection = null;
    }
});





$app->post('/accept_asset', function (Request $request, Response $response) {
    try {
        // Get asset_id from the request
        $asset_id = (int) $request->getParsedBody()['asset_id'] ?? null;

        // Check if asset_id is provided
        if (!$asset_id) {
            // Create a new response object
            $response = new Response();

            // Set the response body and headers manually
            $response->getBody()->write(json_encode(['error' => 'Asset ID not provided', 'success' => false]));
            $response = $response->withHeader('Content-Type', 'application/json')->withStatus(400);

            // Return the response
            return $response;
        }

        // Establish a connection to the MySQL database
        $connection = get_database_connection();

        // Begin a transaction
        $connection->beginTransaction();

        // Fetch the issued asset from issued_assets
        $select_query = "SELECT * FROM issued_assets WHERE asset_id = ?";
        $statement = $connection->prepare($select_query);
        $statement->execute([$asset_id]);
        $issued_asset = $statement->fetch();

        if (!$issued_asset) {
            // Create a new response object
            $response = new Response();

            // Set the response body and headers manually
            $response->getBody()->write(json_encode(['message' => 'Asset not found', 'success' => false]));
            $response = $response->withHeader('Content-Type', 'application/json')->withStatus(404);

            // Return the response
            return $response;
        }

        // Convert the issued_asset tuple to a dictionary for easier access
        $issued_asset_dict = [
            'sbu_name' => $issued_asset['sbu_name'],
            'employee_code' => $issued_asset['employee_code'],
            'employee_name' => $issued_asset['employee_name'],
            'asset_name' => $issued_asset['asset_name'],
            'asset_model' => $issued_asset['asset_model'],
            'serial_number' => $issued_asset['serial_number'],
            'asset_id' => $issued_asset['asset_id'],
            'description' => $issued_asset['description'],
            'issue_date' => $issued_asset['issue_date'],
            'location' => $issued_asset['location'], // Include the location from the fetched asset
            'image_filename' => $issued_asset['image_filename'],
            'type' => $issued_asset['type'],
			'email' => $issued_asset['email']
        ];

        // Update asset_location in all_assets table
        $update_query_all_assets = "UPDATE all_assets SET asset_location = ? WHERE assetId = ?";
        $statement = $connection->prepare($update_query_all_assets);
        $statement->execute([$issued_asset_dict['location'], $asset_id]);

        // Move the issued asset to accepted_issues
        $insert_query_accepted = '
            INSERT INTO accepted_issues
            (sbu_name, employee_code, employee_name, asset_name, asset_model, serial_number, asset_id, description, issue_date, location, image_filename, type, email) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $statement = $connection->prepare($insert_query_accepted);
        $statement->execute([$issued_asset_dict['sbu_name'], $issued_asset_dict['employee_code'], $issued_asset_dict['employee_name'], $issued_asset_dict['asset_name'], $issued_asset_dict['asset_model'], $issued_asset_dict['serial_number'], $issued_asset_dict['asset_id'], $issued_asset_dict['description'], $issued_asset_dict['issue_date'], $issued_asset_dict['location'], $issued_asset_dict['image_filename'], $issued_asset_dict['type'], $issued_asset_dict['email']]);

        // Move the issued asset to logs
        $insert_query_logs = '
            INSERT INTO logs
            (sbu_name, employee_code, employee_name, asset_name, asset_model, serial_number, asset_id, description, location, image_filename, type, issue_date, email) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $statement = $connection->prepare($insert_query_logs);
        $statement->execute([$issued_asset_dict['sbu_name'], $issued_asset_dict['employee_code'], $issued_asset_dict['employee_name'], $issued_asset_dict['asset_name'], $issued_asset_dict['asset_model'], $issued_asset_dict['serial_number'], $issued_asset_dict['asset_id'], $issued_asset_dict['description'], $issued_asset_dict['location'], $issued_asset_dict['image_filename'], $issued_asset_dict['type'], $issued_asset_dict['issue_date'], $issued_asset_dict['email']]);

        // Delete the issued asset from issued_assets
        $delete_query_issued = "DELETE FROM issued_assets WHERE asset_id = ?";
        $statement = $connection->prepare($delete_query_issued);
        $statement->execute([$asset_id]);

        // Delete the asset from assets3 with the matching assetId
        $delete_query_assets3 = "DELETE FROM assets3 WHERE assetId = ?";
        $statement = $connection->prepare($delete_query_assets3);
        $statement->execute([$asset_id]);

        // Commit the transaction
        $connection->commit();

        // Close the connection
        $connection = null;

        // Create a new response object
        $response = new Response();

        // Set the response body and headers manually
        $response->getBody()->write(json_encode(['message' => 'Asset accepted and moved', 'success' => true]));
        $response = $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        // Return the response
        return $response;

    } catch (Exception $e) {
        // Handle the exception here (e.g., log the error, return an error response)

        // Rollback the transaction
        if ($connection) {
            $connection->rollBack();
        }

        // Close the connection
        $connection = null;

        // Create a new response object
        $response = new Response();

        // Set the response body and headers manually
        $response->getBody()->write(json_encode(['error' => $e->getMessage(), 'success' => false]));
        $response = $response->withHeader('Content-Type', 'application/json')->withStatus(500);

        // Return the response
        return $response;
    }
});





// Route to handle request submission
$app->post('/submit_request', function (Request $request, Response $response) {
    try {
        // Extract data from the request
        $sbu_name = $request->getParsedBody()['sbu_name'];
        $employee_name = $request->getParsedBody()['employee_name'];
        $hardware = $request->getParsedBody()['hardware'];
        $description = $request->getParsedBody()['description'];
        $issue_date = $request->getParsedBody()['issue_date'];
        $software_options = json_decode($request->getParsedBody()['software_options'], true); // Parse JSON data
        $location = $request->getParsedBody()['location'];
        $employee_category = $request->getParsedBody()['employee_category'];
        $project_location = $request->getParsedBody()['project_location'];
        $project_name = $request->getParsedBody()['project_name'];
        $can_code = $request->getParsedBody()['can_code'];
        $assets_budget = $request->getParsedBody()['assets_budget'];
        
        // Establish connection to the database
        $connection = get_database_connection();

        // Insert the data into the requests table
        $insert_query = 'INSERT INTO requests (sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $statement = $connection->prepare($insert_query);
        $statement->execute([$sbu_name, $employee_name, $hardware, json_encode($software_options), $description, $issue_date, $location, $employee_category, $project_location, $project_name, $can_code, $assets_budget]);

        // Send request email
        send_request_email('New SBU Requisition', 'tanishksingh@voyants.in', 'A new SBU request has been made.');

        // Close the connection
        $connection = null;

        $responseData = json_encode(['message' => 'Request submitted successfully', 'success' => true]);
        $response->getBody()->write($responseData);
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    } catch (Exception $e) {
        $responseData = json_encode(['message' => 'Error submitting request', 'success' => false]);
        $response->getBody()->write($responseData);
        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }
});


function send_request_email($subject, $recipient_email, $body) {
    // Create a new PHPMailer instance
    $mail = new PHPMailer(true); // Passing true enables exceptions

    try {
        // Server settings
        $mail->isSMTP(); // Set mailer to use SMTP
        $mail->Host = SMTP_SERVER; // SMTP server address
        $mail->Port = SMTP_PORT; 
        $mail->SMTPAuth = true; 
        $mail->Username = SMTP_USERNAME; 
        $mail->Password = SMTP_PASSWORD; // SMTP password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
        
        $mail->SMTPDebug = SMTP::DEBUG_LOWLEVEL;
        
        // Recipients
        $mail->setFrom(SENDER_EMAIL);
        $mail->addAddress($recipient_email);

        // Content
        $mail->isHTML(false); 
        $mail->Subject = $subject;
        $mail->Body = $body;

        // Send email
        $mail->send();

        return true; // Email sent successfully
    } catch (Exception $e) {
        // Log the error
        error_log('Error sending email: ' . $mail->ErrorInfo);
        return false; // Email sending failed
    }
}




// Route to fetch requests made
//$app->get('/requests_made', function (Request $request, Response $response) use ($app) {
//    try {
//        $connection = get_database_connection();
//        $cursor = $connection->cursor();
//        $select_query = '
//            SELECT id, sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
//            FROM requests';
//        $cursor->execute($select_query);
//        $data = $cursor->fetchAll();

//        return $response->withJson($data, 200);
//    } catch (Exception $e) {
//        error_log("Error fetching requests data: " . $e->getMessage());
//        return $response->withJson([], 500);
//    } finally {
//        // Close the connection
//        $connection->close();
//    }
//});


// Route to handle accepting a request
//$app->post('/accept_request', function (Request $request) use ($app) {
//    try {
//        $request_id = $request->request->get('request_id');
//
//        $connection = get_database_connection();
//        $cursor = $connection->cursor();
//        $update_query = '
//            UPDATE requests
//            SET status = "Accepted"
//            WHERE id = ?';
//        $cursor->execute($update_query, [$request_id]);
//        $connection->commit();

//        return new Response(json_encode(['message' => 'Request accepted successfully', 'success' => true]), 200, ['Content-Type' => 'application/json']);
//    } catch (Exception $e) {
//        error_log("Error accepting request: " . $e->getMessage());
//        return new Response(json_encode(['message' => 'Error accepting request', 'success' => false]), 500, ['Content-Type' => 'application/json']);
//    } finally {
//        // Close the connection
//        $connection->close();
//    }
//});



$app->post('/submit_remark', function ($request, $response, $args) use ($app) {
    try {
        // Check if it's a POST request
        if ($request->getMethod() !== 'POST') {
            return $response->withJson(['message' => 'Method not allowed', 'success' => false], 405);
        }

        // Parse JSON body
        $data = $request->getParsedBody();

        // Check if required fields are provided
        if (!isset($data['request_id']) || !isset($data['remark'])) {
            return $response->withJson(['message' => 'Invalid JSON data', 'success' => false], 400);
        }

        // Sanitize input
        $remark = htmlspecialchars($data['remark']);
        $requestId = $data['request_id'];

        // Get sbu_name for the request
        $sbuName = get_sbu_name_for_request($requestId);

        if (!$sbuName) {
            throw new Exception("Sbu_name not found for request_id $requestId");
        }

        // Update status in the database
        $connection = get_database_connection();
        $statement = $connection->prepare('UPDATE requests SET status = :remark WHERE id = :requestId');
        $statement->bindParam(':remark', $remark);
        $statement->bindParam(':requestId', $requestId);
        $statement->execute();

        // Send email based on sbu_name
        $recipientEmails = resolve_recipient_email($sbuName);
        send_email($remark, $recipientEmails);

        // Prepare response
        $responseBody = (new StreamFactory())->createStream(json_encode(['message' => 'Remark submitted successfully', 'success' => true]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200)
            ->withBody($responseBody);

    } catch (Exception $e) {
        error_log("Error submitting remark: " . $e->getMessage());
        return $response->withJson(['message' => 'Error submitting remark', 'success' => false]);
    } finally {
        // Close database connection
        if (isset($connection)) {
            $connection = null;
        }
    }
});

function resolve_recipient_email($sbuName) {
    $recipientEmails = [
        'MD-Cell' => 'updendra@voyants.in',
        'APM' => 'swatiagarwal@voyants.in',
        'Finance' => 'deepakbansal@voyants.in',
        'BD' => 'subirkhare@voyants.in',
        'TRB(Design)' => 'sumeetchakraborty@voyants.in',
        'TRB(PMC)' => 'chandramani@voyants.in',
        'ED' => 'rkmishra@voyants.in',
        'IPD' => 'subharoy@voyants.in',
        'EMS' => 'rksingh@voyants.in',
    ];
    return $recipientEmails[$sbuName] ?? 'tanishksingh@voyants.in';
}

// Client-side JavaScript alert should be handled in client-side code


function send_email($remark, $recipientEmails) {

    //$sender_email = SMTP_USERNAME;
    $subject = 'Requisition Status Update';
    $body = "The status of your request in Assets Management Portal has been updated to: $remark";

    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);

    try {
        // Server set
        $mail->isSMTP();
        $mail->Host = SMTP_SERVER;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->Port = SMTP_PORT; // Change this to your SMTP port if different
        
        $mail->SMTPSecure = 'tls';
		//$mail->SMTPAutoTLS = false;

		
		$mail->SMTPDebug = SMTP::DEBUG_CONNECTION;

        // Recipients
        $mail->setFrom(SENDER_EMAIL, 'Assets Management Portal');
        $mail->addAddress($recipientEmails);

        // Content
        $mail->isHTML(false); // Set email format to HTML
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // Send email
        $mail->send();
        echo 'Email sent successfully';
    } catch (Exception $e) {
        echo "Failed to send email. Error: {$mail->ErrorInfo}";
		echo $mail->Debugoutput;
    }
}

	










function get_sbu_name_for_request($request_id) {
    try {
        // Establish a connection to the database
        $connection = get_database_connection();

        // Prepare the SELECT query
        $select_query = 'SELECT sbu_name FROM requests WHERE id = ?';

        // Prepare and execute the statement
        $statement = $connection->prepare($select_query);
        $statement->execute([$request_id]);

        // Fetch the result
        $result = $statement->fetch(PDO::FETCH_ASSOC);

        // Close the connection
        $connection = null;

        // Check if a result was found
        if ($result) {
            return $result['sbu_name'];
        } else {
            // Handle the case where the request_id is not found
            return null;
        }
    } catch (PDOException $e) {
        // Handle any exceptions
        error_log("Error getting SBU name for request: " . $e->getMessage());
        return null;
    }
}






// Route to handle requests list
$app->get('/requests_list', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT id, sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
        ';
        $statement = $connection->query($select_query);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});

// Route to handle requests list
$app->get('/requests_list_apm', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'APM']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});


// Route to handle requests list
$app->get('/requests_list_ipd', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'IPD']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});


// Route to handle requests list
$app->get('/requests_list_ems', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'EMS']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});


// Route to handle requests list
$app->get('/requests_list_ed', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'ED']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});


// Route to handle requests list
$app->get('/requests_list_bd', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'BD']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});

// Route to handle requests list
$app->get('/requests_list_pms', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'PMS']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});

// Route to handle requests list
$app->get('/requests_list_trbdesign', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'TRB(Design)']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});

// Route to handle requests list
$app->get('/requests_list_finance', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'Finance']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});

// Route to handle requests list
$app->get('/requests_list_trbpmc', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name = :sbu_name
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name' => 'TRB(PMC)']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});

// Route to handle requests list
$app->get('/requests_list_wsd_oms', function ($request, $response) {
    try {
        $connection = get_database_connection();
        $select_query = '
            SELECT sbu_name, employee_name, hardware, software_options, description, issue_date, location, employee_category, project_location, project_name, can_code, assets_budget, status
            FROM requests
            WHERE sbu_name IN (:sbu_name1, :sbu_name2)
        ';
        $statement = $connection->prepare($select_query);
        $statement->execute(['sbu_name1' => 'WSD', 'sbu_name2' => 'OMS']);
        $data = $statement->fetchAll(PDO::FETCH_ASSOC);

        // Pass the data to the template
        ob_start();
        include 'templates/requests_list.html';
        $content = ob_get_clean();

        // Write the rendered content to the response body
        $response->getBody()->write($content);
        return $response;

    } catch (Exception $e) {
        echo "Error fetching requests data: " . $e->getMessage();
        // Handle error if needed
    } finally {
        // Close the database connection
        $connection = null;
    }
});




$app->map(['GET', 'POST'], '/{routes:.+}', function (Request $request, Response $response) {
    $response->getBody()->write('Page not found');
    return $response->withStatus(404);
});
// Run the application
$app->run();



?>

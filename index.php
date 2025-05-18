<?php
$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

session_start();

// Turn on error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('db_connect.php');
// Initialize content
$content = "";

// (then your other logic: handle action, page, etc.)

// Add this function to the top of your file, near other utility functions
function ensureTaxColumnsExist($conn) {
    // Check if tax_rate column exists
    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'tax_rate'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN tax_rate DECIMAL(6,4) DEFAULT 0.0825");
    }
    
    // Check if tax_amount column exists
    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'tax_amount'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN tax_amount DECIMAL(10,2) DEFAULT 0.00");
    }
}


$page = isset($_GET['page']) ? $_GET['page'] : '';
// Check if items need attention (30, 60, or 120 days)
function checkInventoryAging() {
    $conn = connectDB();
    $today = date('Y-m-d');
    $intervals = [
        '30days' => [28, 32],
        '60days' => [58, 62],
        '120days' => [118, 122]
    ];
    $aging_items = [
        'items_30days' => [],
        'items_60days' => [],
        'items_120days' => []
    ];
    foreach ($intervals as $key => $range) {
        list($minDays, $maxDays) = $range;
        $sql = "SELECT i.*, c.name, c.email, c.phone, DATEDIFF('$today', i.date_received) AS days_on_lot
                FROM items i
                JOIN consignors c ON i.consignor_id = c.id
                WHERE i.status = 'active'
                AND DATEDIFF('$today', i.date_received) BETWEEN $minDays AND $maxDays";
        $result = $conn->query($sql);
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $aging_items["items_{$key}"][] = $row;
            }
        }
    }
    $conn->close();
    return $aging_items;
}


// Calculate commission based on item value and type
// Calculate commission based on tiered sale price ranges
function calculateCommission($price) {
    $commission_rate = 0;
    if ($price <= 250) {
        $commission_rate = 0.25; // 25%
    } elseif ($price <= 1000) {
        $commission_rate = 0.10; // 10%
    } elseif ($price <= 5000) {
        $commission_rate = 0.08; // 8%
    } else {
        $commission_rate = 0.06; // 6%
    }
    $commission_amount = $price * $commission_rate;
    return [
        'rate' => $commission_rate * 100, // return as percentage (e.g., 25)
        'amount' => $commission_amount    // dollar amount
    ];
}

// Function to generate a polished consignment agreement
function generateConsignmentAgreement($item_id) {
    $conn = connectDB();
    // Get item and consignor details
    $sql = "SELECT i.*, c.*
        FROM items i
        JOIN consignors c ON i.consignor_id = c.id
        WHERE i.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        require_once('MyPDF.php');
        $pdf = new MyPDF();
        $pdf->SetCreator('Back2Work Equipment');
        $pdf->SetAuthor('Back2Work Equipment');
        $pdf->SetTitle('Consignment Agreement');
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 11);
        // Header
        $pdf->Cell(0, 10, 'Back2Work Equipment', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, '10460 US Hwy 79 E, Oakwood, TX 75855 | (903) 721-5544', 0, 1, 'C');
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', '', 10);
        $today = date('m/d/Y');
        $pdf->Write(0, "Consignment Agreement", '', 0, 'L', true);
        $pdf->Ln(2);
        $pdf->Write(0, "Date: {$today}", '', 0, 'L', true);
        $pdf->Write(0, "Consignor Name: {$data['name']}", '', 0, 'L', true);
        $pdf->Write(0, "Item Description: {$data['description']}", '', 0, 'L', true);
        $pdf->Write(0, "Make/Model: {$data['make_model']}", '', 0, 'L', true);
        $pdf->Write(0, "Asking Price: \$" . number_format($data['asking_price'], 2), '', 0, 'L', true);
        $pdf->Write(0, "Minimum Acceptable Price: \$" . number_format($data['min_price'], 2), '', 0, 'L', true);
        $pdf->Ln(5);
        // Agreement Body
        $pdf->MultiCell(0, 6, <<<EOD
1. ITEM INFORMATION
The Consignor certifies that the information provided above is accurate to the best of their knowledge.
2. CONSIGNMENT PERIOD
The consignment term is 120 days from the date of this agreement. If the item is not sold within this time frame, the Consignee (Back2Work Equipment) will contact the Consignor to discuss options such as:
- Price adjustment
- Extension of the listing period
- Return or pickup of the item
If the Consignor does not respond within 7 days of notification, the item may be deemed abandoned, and a storage/removal fee may apply.
3. COMMISSION STRUCTURE
Commission rates and minimums are determined based on the current Back2Work Equipment commission schedule. The commission amount will be deducted from the final sale price.
4. OWNERSHIP & LEGAL RESPONSIBILITY
The Consignor affirms they are the rightful owner of the item and that it is free from any liens, security interests, or claims. No formal title exists for the item unless otherwise disclosed. The Consignor agrees to indemnify Back2Work Equipment against any ownership disputes or claims.
5. ABANDONMENT AND STORAGE FEES
Items left beyond the agreed consignment term without communication will be subject to a storage/removal fee, charged to the credit card on file.
6. CREDIT CARD AUTHORIZATION
The Consignor agrees to provide a valid credit card. The card will only be charged if:
- The Consignor fails to respond within the abandonment window.
- Storage/removal fees become applicable.
Cardholder Initials: _______   Date: _______
7. LIABILITY DISCLAIMER
Back2Work Equipment is not responsible for theft, weather-related damage, or mechanical failures. Items are consigned as-is without warranty.
8. PAYMENT TO CONSIGNOR
Payment will be issued based on the Consignorâ€™s selected payment preference after the sale is completed.
Payee Name: __________________________________
Payment Contact Info: __________________________________
SIGNATURES
Consignor Signature: ___________________________    Date: ___________
Consignee (Back2Work Equipment) Signature: ___________________________    Date: ___________
EOD
        , 0, 'L');
        $conn->close();
        $pdf->Output("consignment_agreement_item_{$item_id}.pdf", 'I');
        exit;
    }
    $conn->close();
    return false;
}

function generateInvoice($sale_id) {
    $conn = connectDB();

    $sql = "SELECT s.*, i.description, i.make_model, i.serial_number, c.name as consignor_name
            FROM sales s
            JOIN items i ON s.item_id = i.id
            JOIN consignors c ON i.consignor_id = c.id
            WHERE s.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();

        // Safely extract numeric values
        $sale_price      = floatval($data['sale_price']);
        $sales_tax       = floatval($data['sales_tax']);
        $delivery_fee    = floatval($data['delivery_fee']);
        $credit_applied  = floatval($data['credit_applied']);
        $total_amount    = $sale_price + $sales_tax + $delivery_fee;
        $final_due       = $total_amount - $credit_applied;

        $invoice = "<div class='invoice-box'>";
        $invoice .= "<h1>BACK2WORK EQUIPMENT</h1>";
        $invoice .= "<h2>Sales Invoice</h2><hr>";

        $invoice .= "<p><strong>Invoice #:</strong> " . str_pad($data['id'], 5, '0', STR_PAD_LEFT) . "</p>";
        $invoice .= "<p><strong>Date:</strong> " . date('m/d/Y', strtotime($data['sale_date'])) . "</p>";
        $invoice .= "<p><strong>Sold To:</strong> " . htmlspecialchars($data['buyer_name']) . "</p>";
        if (!empty($data['buyer_phone'])) {
            $invoice .= "<p><strong>Phone:</strong> " . htmlspecialchars($data['buyer_phone']) . "</p>";
        }
        if (!empty($data['buyer_address'])) {
            $invoice .= "<p><strong>Address:</strong> " . nl2br(htmlspecialchars($data['buyer_address'])) . "</p>";
        }

        $invoice .= "<hr>";
        $invoice .= "<p><strong>Item:</strong> " . htmlspecialchars($data['description']) . " (" . htmlspecialchars($data['make_model']) . ")</p>";
        if (!empty($data['serial_number'])) {
            $invoice .= "<p><strong>Serial Number:</strong> " . htmlspecialchars($data['serial_number']) . "</p>";
        }
        $invoice .= "<p><strong>Consignor:</strong> " . htmlspecialchars($data['consignor_name']) . "</p>";

        // Delivery details (if applicable)
        if (!empty($data['delivery_method'])) {
            $invoice .= "<hr>";
            $invoice .= "<p><strong>Delivery Method:</strong> " . ucfirst(htmlspecialchars($data['delivery_method'])) . "</p>";
            if (!empty($data['scheduled_time']) && $data['scheduled_time'] !== '0000-00-00 00:00:00') {
                $scheduled = date('m/d/Y g:i A', strtotime($data['scheduled_time']));
                $invoice .= "<p><strong>Scheduled Time:</strong> {$scheduled}</p>";
            }
            if (!empty($data['mileage']) || $data['mileage'] > 0) {
                $invoice .= "<p><strong>Mileage (one-way):</strong> " . floatval($data['mileage']) . " miles</p>";
            }
        } else {
            $invoice .= "<hr>";
            $invoice .= "<p><strong>Delivery Method:</strong> None</p>";
        }

        // Financial summary
        $invoice .= "<hr>";
        $invoice .= "<p><strong>Sale Price:</strong> $" . number_format($sale_price, 2) . "</p>";
        if ($delivery_fee > 0) {
            $invoice .= "<p><strong>Delivery Fee:</strong> $" . number_format($delivery_fee, 2) . "</p>";
        }
        $invoice .= "<p><strong>Sales Tax (8.25%):</strong> $" . number_format($sales_tax, 2) . "</p>";
        $invoice .= "<p><strong>Total Amount:</strong> $" . number_format($total_amount, 2) . "</p>";

        if ($credit_applied > 0) {
            $invoice .= "<p><strong>Store Credit Applied:</strong> -$" . number_format($credit_applied, 2) . "</p>";
            $invoice .= "<p><strong>Final Amount Due:</strong> $" . number_format($final_due, 2) . "</p>";
        }

        $invoice .= "<p><strong>Payment Method:</strong> " . htmlspecialchars($data['payment_method']) . "</p>";

        // Signature line
        $invoice .= "<hr>";
        $invoice .= "<p><strong>Customer Signature:</strong> ______________________________________</p>";
        $invoice .= "<p><em>By signing, customer agrees that all sales are final.</em></p>";

        $invoice .= "<div style='text-align:center;margin-top:30px;'>";
        $invoice .= "<a href='?page=sales_history' class='btn btn-secondary'>View Sales to Print</a>";
        $invoice .= "</div></div>";

        $conn->close();
        return $invoice;
    }

    $conn->close();
    return "<p class='text-danger'>Sale record not found.</p>";
}

//VIEW DETAILS
if (isset($_GET['page']) && $_GET['page'] === 'item_details' && isset($_GET['item_id'])) {
    $item_id = (int) $_GET['item_id'];
    $conn = connectDB();

    // Get item details along with consignor information
    $stmt = $conn->prepare("SELECT i.*, c.name AS consignor_name 
                           FROM items i 
                           LEFT JOIN consignors c ON i.consignor_id = c.id 
                           WHERE i.id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();

    $content = ''; // Make sure you define it here

    if ($item) {
        $content .= "<h2 class='mt-4'>Item Details: " . htmlspecialchars($item['description']) . "</h2>";
        
        // Basic Item Information Card
        $content .= "<div class='card mb-4'>";
        $content .= "<div class='card-header bg-primary text-white'>";
        $content .= "<h4 class='mb-0'>Basic Information</h4>";
        $content .= "</div>";
        $content .= "<div class='card-body'>";
        $content .= "<div class='row'>";
        $content .= "<div class='col-md-6'>";
        $content .= "<table class='table table-striped'>";
        $content .= "<tr><th>Make/Model:</th><td>" . htmlspecialchars($item['make_model']) . "</td></tr>";
        $content .= "<tr><th>Serial Number:</th><td>" . htmlspecialchars($item['serial_number'] ?? '') . "</td></tr>";
        $content .= "<tr><th>Category:</th><td>" . htmlspecialchars($item['category']) . "</td></tr>";
        $content .= "<tr><th>Consignor:</th><td>" . htmlspecialchars($item['consignor_name'] ?? 'House Inventory') . "</td></tr>";
        $content .= "<tr><th>Date Added:</th><td>" . date('F j, Y', strtotime($item['date_received'])) . "</td></tr>";
        $content .= "<tr><th>Days on Lot:</th><td>" . intval((time() - strtotime($item['date_received'])) / 86400) . " days</td></tr>";
        $content .= "</table>";
        $content .= "</div>";
        
        $content .= "<div class='col-md-6'>";
        $content .= "<table class='table table-striped'>";
        $content .= "<tr><th>Asking Price:</th><td>$" . number_format($item['asking_price'], 2) . "</td></tr>";
        $content .= "<tr><th>Minimum Price:</th><td>$" . number_format($item['min_price'], 2) . "</td></tr>";
        $content .= "<tr><th>Status:</th><td>" . htmlspecialchars($item['status']) . "</td></tr>";
        $content .= "<tr><th>Rental Authorized:</th><td>" . ($item['rental_authorized'] ? "Yes" : "No") . "</td></tr>";
        $content .= "<tr><th>Trade Authorized:</th><td>" . ($item['is_trade_authorized'] ? "Yes" : "No") . "</td></tr>";
        $content .= "</table>";
        $content .= "</div>";
        $content .= "</div>"; // End row
        $content .= "</div>"; // End card-body
        $content .= "</div>"; // End card
        
        // Title Information (if applicable)
        if (!empty($item['is_titled']) && $item['is_titled'] == 1) {
            $content .= "<div class='card mb-4'>";
            $content .= "<div class='card-header bg-info text-white'>";
            $content .= "<h4 class='mb-0'>Title Information</h4>";
            $content .= "</div>";
            $content .= "<div class='card-body'>";
            $content .= "<div class='row'>";
            
            // Title details column
            $content .= "<div class='col-md-6'>";
            $content .= "<table class='table table-striped'>";
            
            if (!empty($item['title_number'])) {
                $content .= "<tr><th>Title Number:</th><td>" . htmlspecialchars($item['title_number']) . "</td></tr>";
            }
            
            if (!empty($item['title_state'])) {
                $states = [
                    'AL'=>'Alabama', 'AK'=>'Alaska', 'AZ'=>'Arizona', 'AR'=>'Arkansas', 'CA'=>'California',
                    'CO'=>'Colorado', 'CT'=>'Connecticut', 'DE'=>'Delaware', 'FL'=>'Florida', 'GA'=>'Georgia',
                    'HI'=>'Hawaii', 'ID'=>'Idaho', 'IL'=>'Illinois', 'IN'=>'Indiana', 'IA'=>'Iowa',
                    'KS'=>'Kansas', 'KY'=>'Kentucky', 'LA'=>'Louisiana', 'ME'=>'Maine', 'MD'=>'Maryland',
                    'MA'=>'Massachusetts', 'MI'=>'Michigan', 'MN'=>'Minnesota', 'MS'=>'Mississippi', 'MO'=>'Missouri',
                    'MT'=>'Montana', 'NE'=>'Nebraska', 'NV'=>'Nevada', 'NH'=>'New Hampshire', 'NJ'=>'New Jersey',
                    'NM'=>'New Mexico', 'NY'=>'New York', 'NC'=>'North Carolina', 'ND'=>'North Dakota', 'OH'=>'Ohio',
                    'OK'=>'Oklahoma', 'OR'=>'Oregon', 'PA'=>'Pennsylvania', 'RI'=>'Rhode Island', 'SC'=>'South Carolina',
                    'SD'=>'South Dakota', 'TN'=>'Tennessee', 'TX'=>'Texas', 'UT'=>'Utah', 'VT'=>'Vermont',
                    'VA'=>'Virginia', 'WA'=>'Washington', 'WV'=>'West Virginia', 'WI'=>'Wisconsin', 'WY'=>'Wyoming',
                    'DC'=>'District of Columbia'
                ];
                $state_name = isset($states[$item['title_state']]) ? $states[$item['title_state']] : $item['title_state'];
                $content .= "<tr><th>Title State:</th><td>" . htmlspecialchars($state_name) . "</td></tr>";
            }
            
            if (!empty($item['title_issue_date']) && $item['title_issue_date'] != '0000-00-00') {
                $content .= "<tr><th>Title Issue Date:</th><td>" . date('F j, Y', strtotime($item['title_issue_date'])) . "</td></tr>";
            }
            
            if (!empty($item['title_holder'])) {
                $content .= "<tr><th>Title Holder:</th><td>" . htmlspecialchars($item['title_holder']) . "</td></tr>";
            }
            
            $content .= "</table>";
            $content .= "</div>";
            
            // VIN and status column
            $content .= "<div class='col-md-6'>";
            $content .= "<table class='table table-striped'>";
            
            if (!empty($item['vin'])) {
                $content .= "<tr><th>VIN/Serial:</th><td>" . htmlspecialchars($item['vin']) . "</td></tr>";
            }
            
            if (!empty($item['title_status'])) {
                $status_labels = [
                    'clear' => '<span class="badge badge-success">Clear Title</span>',
                    'lien' => '<span class="badge badge-warning">Has Lien</span>',
                    'salvage' => '<span class="badge badge-danger">Salvage Title</span>',
                    'rebuilt' => '<span class="badge badge-info">Rebuilt/Reconstructed</span>',
                    'bonded' => '<span class="badge badge-secondary">Bonded Title</span>',
                    'pending' => '<span class="badge badge-warning">Pending/In Process</span>'
                ];
                $status_display = isset($status_labels[$item['title_status']]) ? $status_labels[$item['title_status']] : htmlspecialchars($item['title_status']);
                $content .= "<tr><th>Title Status:</th><td>" . $status_display . "</td></tr>";
            }
            
            // Title possession status
            $possession_status = (!empty($item['title_in_possession']) && $item['title_in_possession'] == 1) 
                ? '<span class="badge badge-success">Yes, in our possession</span>' 
                : '<span class="badge badge-danger">Title not in our possession</span>';
            $content .= "<tr><th>Title in Possession:</th><td>" . $possession_status . "</td></tr>";
            
            $content .= "</table>";
            
            // Warning if title not in possession
            if (empty($item['title_in_possession']) || $item['title_in_possession'] != 1) {
                $content .= "<div class='alert alert-danger'>";
                $content .= "<strong>Warning:</strong> We do not have physical possession of this title. ";
                $content .= "This item cannot be sold until the title is secured.";
                $content .= "</div>";
            }
            
            $content .= "</div>";
            $content .= "</div>"; // End row
            $content .= "</div>"; // End card-body
            $content .= "</div>"; // End card
        }
        
        // Condition & Disclosures Card
        $content .= "<div class='card mb-4'>";
        $content .= "<div class='card-header bg-warning text-dark'>";
        $content .= "<h4 class='mb-0'>Condition & Disclosures</h4>";
        $content .= "</div>";
        $content .= "<div class='card-body'>";
        
        // General condition
        $content .= "<h5>General Condition:</h5>";
        $content .= "<p>" . nl2br(htmlspecialchars($item['condition_desc'] ?? 'Not specified')) . "</p>";
        
        $content .= "<div class='row mt-3'>";
        $content .= "<div class='col-md-6'>";
        
        // Hours used (if available)
        if (!empty($item['hours_used'])) {
            $content .= "<div class='mb-3'>";
            $content .= "<strong>Hours Used:</strong> " . htmlspecialchars($item['hours_used']) . " hours";
            $content .= "</div>";
        }
        
        // Last maintenance date
        if (!empty($item['last_maintenance_date']) && $item['last_maintenance_date'] != '0000-00-00') {
            $content .= "<div class='mb-3'>";
            $content .= "<strong>Last Maintenance:</strong> " . date('F j, Y', strtotime($item['last_maintenance_date']));
            $content .= "</div>";
        }
        
        // Known issues
        $content .= "<div class='mb-3'>";
        $content .= "<strong>Known Issues:</strong><br>";
        $content .= empty($item['known_issues']) ? "No known issues reported" : nl2br(htmlspecialchars($item['known_issues']));
        $content .= "</div>";
        
        $content .= "</div>"; // End left column
        
        $content .= "<div class='col-md-6'>";
        
        // Signs of wear
        $content .= "<div class='mb-3'>";
        $content .= "<strong>Signs of Wear:</strong><br>";
        $content .= empty($item['wear_description']) ? "Not specified" : nl2br(htmlspecialchars($item['wear_description']));
        $content .= "</div>";
        
        // Maintenance history
        $content .= "<div class='mb-3'>";
        $content .= "<strong>Maintenance History:</strong><br>";
        $content .= empty($item['maintenance_history']) ? "No maintenance history available" : nl2br(htmlspecialchars($item['maintenance_history']));
        $content .= "</div>";
        
        $content .= "</div>"; // End right column
        $content .= "</div>"; // End row
        $content .= "</div>"; // End card-body
        $content .= "</div>"; // End card
        
        // House inventory specific information (if applicable)
        if ($item['consignor_name'] === 'House Inventory' || $item['owned_by_company']) {
            $content .= "<div class='card mb-4'>";
            $content .= "<div class='card-header bg-secondary text-white'>";
            $content .= "<h4 class='mb-0'>House Inventory Details</h4>";
            $content .= "</div>";
            $content .= "<div class='card-body'>";
            
            $content .= "<table class='table table-striped'>";
            
            if (!empty($item['purchase_source'])) {
                $content .= "<tr><th>Purchased From:</th><td>" . htmlspecialchars($item['purchase_source']) . "</td></tr>";
            }
            
            if (!empty($item['purchase_price'])) {
                $content .= "<tr><th>Purchase Price:</th><td>$" . number_format($item['purchase_price'], 2) . "</td></tr>";
            }
            
            if (!empty($item['date_acquired']) && $item['date_acquired'] != '0000-00-00') {
                $content .= "<tr><th>Date Acquired:</th><td>" . date('F j, Y', strtotime($item['date_acquired'])) . "</td></tr>";
            }
            
            $content .= "</table>";
            $content .= "</div>"; // End card-body
            $content .= "</div>"; // End card
        }
        
        // Additional Notes
        if (!empty($item['notes'])) {
            $content .= "<div class='card mb-4'>";
            $content .= "<div class='card-header'>";
            $content .= "<h4 class='mb-0'>Additional Notes</h4>";
            $content .= "</div>";
            $content .= "<div class='card-body'>";
            $content .= "<p>" . nl2br(htmlspecialchars($item['notes'])) . "</p>";
            $content .= "</div>"; // End card-body
            $content .= "</div>"; // End card
        }

        // Pickup info
        $formatted_pickup = (!empty($item['scheduled_pickup']) && $item['scheduled_pickup'] !== '0000-00-00 00:00:00') 
            ? (new DateTime($item['scheduled_pickup']))->format('F j, Y @ g:i A') 
            : 'Not scheduled';

        $content .= "<div class='card mb-4'>";
        $content .= "<div class='card-header'>";
        $content .= "<h4 class='mb-0'>Pickup Information</h4>";
        $content .= "</div>";
        $content .= "<div class='card-body'>";
        $content .= "<div class='row'>";
        $content .= "<div class='col-md-6'>";
        $content .= "<table class='table table-striped'>";
        $content .= "<tr><th>Pickup Phone:</th><td>" . htmlspecialchars($item['pickup_phone']) . "</td></tr>";
        $content .= "<tr><th>Pickup Address:</th><td>" . htmlspecialchars($item['pickup_address']) . "</td></tr>";
        $content .= "</table>";
        $content .= "</div>";
        
        $content .= "<div class='col-md-6'>";
        $content .= "<table class='table table-striped'>";
        $content .= "<tr><th>Scheduled Pickup:</th><td>" . $formatted_pickup . "</td></tr>";
        $content .= "<tr><th>One-Way Mileage:</th><td>" . htmlspecialchars($item['mileage']) . "</td></tr>";
        $content .= "</table>";
        $content .= "</div>";
        $content .= "</div>"; // End row

        if (!empty($item['pickup_canceled']) && $item['pickup_canceled'] == 1) {
            $cancel_at = new DateTime($item['pickup_canceled_at']);
            $content .= "<div class='alert alert-warning'><strong>Pickup Canceled:</strong><br>";
            $content .= "<strong>Reason:</strong> " . htmlspecialchars($item['pickup_canceled_reason']) . "<br>";
            $content .= "<strong>When:</strong> " . $cancel_at->format('F j, Y @ g:i A') . "</div>";
        }
        
        $content .= "</div>"; // End card-body
        $content .= "</div>"; // End card
        
        // Action buttons
        $content .= "<div class='mb-4'>";
        $content .= "<a href='?page=inventory' class='btn btn-secondary'>Back to Inventory</a> ";
        $content .= "<a href='?page=edit_item&item_id={$item_id}' class='btn btn-primary'>Edit Item</a> ";
        
        if ($item['rental_authorized']) {
            $content .= "<a href='?page=create_rental&item_id={$item_id}' class='btn btn-success'>Start Rental</a> ";
        }
        
        if ($item['is_trade_authorized']) {
            $content .= "<a href='?page=inventory&action=start_trade&item_id={$item_id}' class='btn btn-info'>Start Trade</a> ";
        }
        
        $content .= "<a href='?page=inventory&action=record_sale&item_id={$item_id}' class='btn btn-success'>Sell Item</a>";
        $content .= "</div>";
        
    } else {
        $content .= "<div class='alert alert-danger'>Item not found.</div>";
    }

    $stmt->close();
    $conn->close();

    // Return early to avoid falling into other logic
    echo displayPage($content);
    exit;
}

if ($action == 'record_sale' && isset($_GET['item_id'])) {
    $item_id = (int) $_GET['item_id'];
    $conn = connectDB();
    $stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();

    if ($item) {
        ob_start();
        ?>
        <h2>Record Sale for: <?= htmlspecialchars($item['description']) ?></h2>
        <form method="post" action="?action=save_sale">
            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
            <div class="form-group">
                <label>Sale Date:</label>
                <input type="date" name="sale_date" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Sale Price ($):</label>
                <input type="number" step="0.01" name="sale_price" class="form-control" value="<?= htmlspecialchars($item['asking_price']) ?>" required>
            </div>
            <div class="form-group">
                <label>Buyer Name:</label>
                <input type="text" name="buyer_name" class="form-control">
            </div>
            <div class="form-group">
                <label>Buyer Contact Info:</label>
                <textarea name="buyer_contact" class="form-control"></textarea>
            </div>

            <div class="form-group">
                <label>Scheduled Time:</label>
                <input type="datetime-local" name="scheduled_time" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Mileage (one-way, if delivery):</label>
                <input type="number" step="0.1" name="mileage" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary">Save Sale</button>
        </form>
        <?php
        $content = isset($content) ? $content : '';
        $content .= ob_get_clean();
    } else {
        $content .= "<div class='alert alert-danger'>Item not found.</div>";
    }
    $stmt->close();
    $conn->close();
}


// ====================[ PAGE: STORE CREDIT HISTORY ]====================
if (isset($page) && $page === 'store_credit') {
    $conn = connectDB();
    $content .= "<h2 class='mb-4'>Store Credit Summary by Customer</h2>";
    $sql = "SELECT customer_name, SUM(amount) AS total_credit 
            FROM customer_credits 
            GROUP BY customer_name 
            HAVING total_credit > 0 
            ORDER BY total_credit DESC";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $content .= "<div class='table-responsive'>";
        $content .= "<table class='table table-striped'>";
        $content .= "<thead><tr>
                        <th>Customer</th>
                        <th>Total Credit</th>
                    </tr></thead><tbody>";
        while ($row = $result->fetch_assoc()) {
            $content .= "<tr>
                            <td>" . htmlspecialchars($row['customer_name']) . "</td>
                            <td>$" . number_format($row['total_credit'], 2) . "</td>
                        </tr>";
        }
        $content .= "</tbody></table></div>";
    } else {
        $content .= "<div class='alert alert-info'>No store credit entries found.</div>";
    }
    $conn->close();
}

// Dashboard/home page function
function displayDashboard($page) {
    $conn = connectDB();
    
    // Get active inventory count
    $sql_active = "SELECT COUNT(*) as active_count FROM items WHERE status = 'active'";
    $result_active = $conn->query($sql_active);
    $active_count = $result_active->fetch_assoc()['active_count'];
    
    // Get items needing attention
    $aging_items = checkInventoryAging();
    $items_30days_count = count($aging_items['items_30days']);
    $items_60days_count = count($aging_items['items_60days']);
    $items_120days_count = count($aging_items['items_120days']); // <-- NEW
    // Get recent sales (last 30 days)
    $sql_sales = "SELECT SUM(commission_amount) as total_commission, COUNT(*) as sales_count 
                FROM sales 
                WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $result_sales = $conn->query($sql_sales);
    $sales_data = $result_sales->fetch_assoc();
    // Get recent sales (last 120 days)
    $sql_sales_120 = "SELECT SUM(commission_amount) as total_commission_120, COUNT(*) as sales_count_120 
                FROM sales 
                WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)";
    $result_sales_120 = $conn->query($sql_sales_120);
    $sales_data_120 = $result_sales_120->fetch_assoc();
    $conn->close();
    // Output dashboard data
    $output = '<style>
    .tabs a { padding: 8px 12px; margin-right: 8px; background: #eee; text-decoration: none; border-radius: 5px; }
    .tabs a.active { font-weight: bold; background: #fff; border: 1px solid #ccc; border-bottom: none; }
    </style>';
    $output .= "<h2 class='mt-4'>Dashboard</h2>";
    $output .= "<div class='dashboard-stats'>";
    $output .= "<div class='stat-box'><h3>Active Inventory</h3><p class='stat-count'>{$active_count}</p></div>";
    $output .= "<div class='stat-box warning'><h3>30-Day Alerts</h3><p class='stat-count'>{$items_30days_count}</p></div>";
    $output .= "<div class='stat-box danger'><h3>60-Day Alerts</h3><p class='stat-count'>{$items_60days_count}</p></div>";
    $output .= "<div class='stat-box danger'><h3>120-Day Alerts</h3><p class='stat-count'>{$items_120days_count}</p></div>"; // <-- NEW
    $output .= "<div class='stat-box success'><h3>Monthly Sales</h3><p class='stat-count'>{$sales_data['sales_count']}</p></div>";
    $output .= "<div class='stat-box success'><h3>Monthly Commission</h3><p class='stat-count'>$" . number_format($sales_data['total_commission'], 2) . "</p></div>";
    $output .= "<div class='stat-box success'><h3>120-Day Sales</h3><p class='stat-count'>{$sales_data_120['sales_count_120']}</p></div>"; // <-- NEW
    $output .= "<div class='stat-box success'><h3>120-Day Commission</h3><p class='stat-count'>$" . number_format($sales_data_120['total_commission_120'], 2) . "</p></div>"; // <-- NEW
    $output .= "</div>";
    return $output;
}


function displayAddConsignorForm() {
    $output = "<h2 class='mt-4'>Add New Consignor</h2>";
    $output .= "<div class='text-danger font-weight-bold mb-3'>You can edit the consignor's details after saving.</div>";

    $output .= "<form method='post' action='?action=save_consignor'>";
    $output .= "<div class='text-right mt-3'>
        <a href='?action=generate_blank_agreement' target='_blank' class='btn btn-sm btn-warning ml-2'>Print Blank Agreement</a>
    </div>";
    $output .= "<div class='form-group'>
        <label for='name'>Name:</label>
        <input type='text' name='name' id='name' required class='form-control'>
    </div>";
    $output .= "<div class='form-group'>
        <label for='email'>Email:</label>
        <input type='email' name='email' id='email' class='form-control'>
    </div>";
    $output .= "<div class='form-group'>
        <label for='phone'>Phone:</label>
        <input type='text' name='phone' id='phone' class='form-control'>
    </div>";
    $output .= "<div class='form-group'>
        <label for='address'>Address:</label>
        <textarea name='address' id='address' class='form-control'></textarea>
    </div>";
    $output .= "<button type='submit' class='btn btn-primary mt-3'>Save Consignor</button>";
    $output .= "</form>";
    return $output;
}

function displayAddItemForm() {
    $conn = connectDB();
    // Ensure no preselection for new items
    $item = [];
    $item['consignor_id'] = 0;
    // Get consignors for dropdown
    $sql = "SELECT id, name FROM consignors 
        ORDER BY (name = 'House Inventory') DESC, name";
    $result = $conn->query($sql);
    $output = "<h2 class='mt-4'>Add New Item</h2>";
    $output .= "<form method='post' action='?action=save_item'>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='consignor_id'>Consignor:</label>";
    $output .= "<select name='consignor_id' id='consignor_id' required class='form-control'>";
    $output .= "<option value=''>-- Select Consignor --</option>";
    while ($row = $result->fetch_assoc()) {
        $selected = ($row['id'] == $item['consignor_id']) ? 'selected' : '';
        $output .= "<option value='{$row['id']}' {$selected}>{$row['name']}</option>";
    }
    $output .= "</select></div>";
    
    // Basic item details
    $output .= "<div class='form-group'>";
    $output .= "<label for='description'>Description:</label>";
    $output .= "<input type='text' name='description' id='description' required class='form-control'>";
    $output .= "</div>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='make_model'>Make/Model:</label>";
    $output .= "<input type='text' name='make_model' id='make_model' class='form-control'>";
    $output .= "</div>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='serial_number'>Serial Number:</label>";
    $output .= "<input type='text' name='serial_number' id='serial_number' class='form-control'>";
    $output .= "</div>";
    
    // House Inventory specific fields (shown conditionally via JavaScript)
    $output .= "<div id='house_inventory_fields' style='display:none;'>";
    $output .= "<h4 class='mt-3'>House Inventory Details</h4>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='purchase_source'>Purchase Source:</label>";
    $output .= "<input type='text' name='purchase_source' id='purchase_source' class='form-control' placeholder='Where was this item purchased from?'>";
    $output .= "</div>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='purchase_price'>Purchase Price ($):</label>";
    $output .= "<input type='number' name='purchase_price' id='purchase_price' step='0.01' class='form-control'>";
    $output .= "</div>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='date_acquired'>Date Acquired:</label>";
    $output .= "<input type='date' name='date_acquired' id='date_acquired' class='form-control'>";
    $output .= "</div>";
    $output .= "</div>";
    
    // Add an enhanced condition section (expanded from the original one)
    $output .= "<div class='card mb-3'>";
    $output .= "<div class='card-header'><h4>Item Condition & Disclosures</h4></div>";
    $output .= "<div class='card-body'>";
    
    // Basic condition field (existing)
    $output .= "<div class='form-group'>";
    $output .= "<label for='condition_desc'>General Condition:</label>";
    $output .= "<textarea name='condition_desc' id='condition_desc' class='form-control' placeholder='Describe the overall condition of the item'></textarea>";
    $output .= "</div>";
    
    // New detailed disclosure fields
    $output .= "<div class='form-group'>";
    $output .= "<label for='hours_used'>Hours Used (if applicable):</label>";
    $output .= "<input type='number' name='hours_used' id='hours_used' class='form-control'>";
    $output .= "</div>";
    
    $output .= "<div class='form-group'>";
    $output .= "<label for='maintenance_history'>Maintenance History:</label>";
    $output .= "<textarea name='maintenance_history' id='maintenance_history' class='form-control' placeholder='Service records, past repairs, etc.'></textarea>";
    $output .= "</div>";
    
    $output .= "<div class='form-group'>";
    $output .= "<label for='last_maintenance_date'>Last Maintenance Date:</label>";
    $output .= "<input type='date' name='last_maintenance_date' id='last_maintenance_date' class='form-control'>";
    $output .= "</div>";
    
    $output .= "<div class='form-group'>";
    $output .= "<label for='known_issues'>Known Issues/Defects:</label>";
    $output .= "<textarea name='known_issues' id='known_issues' class='form-control' placeholder='Any known defects, leaks, bad tires, etc.'></textarea>";
    $output .= "</div>";
    
    $output .= "<div class='form-group'>";
    $output .= "<label for='wear_description'>Signs of Wear/Damage:</label>";
    $output .= "<textarea name='wear_description' id='wear_description' class='form-control' placeholder='Visible scratches, dents, wear and tear, etc.'></textarea>";
    $output .= "</div>";
    $output .= "</div>"; // End card-body
    $output .= "</div>"; // End card
    
    // Add Title Information Section
    $output .= "<div class='card mb-3'>";
    $output .= "<div class='card-header'>";
    $output .= "<div class='d-flex justify-content-between align-items-center'>";
    $output .= "<h4 class='mb-0'>Title Information</h4>";
    $output .= "<div class='custom-control custom-switch'>";
    $output .= "<input type='checkbox' class='custom-control-input' id='is_titled' name='is_titled' value='1' onchange='toggleTitleFields()'>";
    $output .= "<label class='custom-control-label' for='is_titled'>This item is titled</label>";
    $output .= "</div>";
    $output .= "</div>";
    $output .= "</div>";

    $output .= "<div class='card-body' id='title_fields' style='display:none;'>";
    $output .= "<div class='alert alert-info'>";
    $output .= "<i class='fas fa-info-circle'></i> Title information is required for vehicles, trailers, and other DMV-registered equipment.";
    $output .= "</div>";

    $output .= "<div class='row'>";
    $output .= "<div class='col-md-6'>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='title_number'>Title Number:</label>";
    $output .= "<input type='text' name='title_number' id='title_number' class='form-control' placeholder='DMV title number/reference'>";
    $output .= "</div>";
    $output .= "</div>";

    $output .= "<div class='col-md-6'>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='title_state'>Title State:</label>";
    $output .= "<select name='title_state' id='title_state' class='form-control'>";
    $output .= "<option value=''>-- Select State --</option>";
    $states = [
        'AL'=>'Alabama', 'AK'=>'Alaska', 'AZ'=>'Arizona', 'AR'=>'Arkansas', 'CA'=>'California',
        'CO'=>'Colorado', 'CT'=>'Connecticut', 'DE'=>'Delaware', 'FL'=>'Florida', 'GA'=>'Georgia',
        'HI'=>'Hawaii', 'ID'=>'Idaho', 'IL'=>'Illinois', 'IN'=>'Indiana', 'IA'=>'Iowa',
        'KS'=>'Kansas', 'KY'=>'Kentucky', 'LA'=>'Louisiana', 'ME'=>'Maine', 'MD'=>'Maryland',
        'MA'=>'Massachusetts', 'MI'=>'Michigan', 'MN'=>'Minnesota', 'MS'=>'Mississippi', 'MO'=>'Missouri',
        'MT'=>'Montana', 'NE'=>'Nebraska', 'NV'=>'Nevada', 'NH'=>'New Hampshire', 'NJ'=>'New Jersey',
        'NM'=>'New Mexico', 'NY'=>'New York', 'NC'=>'North Carolina', 'ND'=>'North Dakota', 'OH'=>'Ohio',
        'OK'=>'Oklahoma', 'OR'=>'Oregon', 'PA'=>'Pennsylvania', 'RI'=>'Rhode Island', 'SC'=>'South Carolina',
        'SD'=>'South Dakota', 'TN'=>'Tennessee', 'TX'=>'Texas', 'UT'=>'Utah', 'VT'=>'Vermont',
        'VA'=>'Virginia', 'WA'=>'Washington', 'WV'=>'West Virginia', 'WI'=>'Wisconsin', 'WY'=>'Wyoming',
        'DC'=>'District of Columbia'
    ];
    foreach($states as $abbr => $state) {
        $output .= "<option value='{$abbr}'>{$state}</option>";
    }
    $output .= "</select>";
    $output .= "</div>";
    $output .= "</div>";
    $output .= "</div>";

    $output .= "<div class='row'>";
    $output .= "<div class='col-md-6'>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='vin'>VIN/Serial:</label>";
    $output .= "<input type='text' name='vin' id='vin' class='form-control' placeholder='Vehicle Identification Number'>";
    $output .= "<small class='form-text text-muted'>For vehicles, enter the 17-digit VIN. For other equipment, enter the serial number.</small>";
    $output .= "</div>";
    $output .= "</div>";

    $output .= "<div class='col-md-6'>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='title_status'>Title Status:</label>";
    $output .= "<select name='title_status' id='title_status' class='form-control'>";
    $output .= "<option value='clear'>Clear Title</option>";
    $output .= "<option value='lien'>Has Lien</option>";
    $output .= "<option value='salvage'>Salvage Title</option>";
    $output .= "<option value='rebuilt'>Rebuilt/Reconstructed</option>";
    $output .= "<option value='bonded'>Bonded Title</option>";
    $output .= "<option value='pending'>Pending/In Process</option>";
    $output .= "</select>";
    $output .= "</div>";
    $output .= "</div>";
    $output .= "</div>";

    $output .= "<div class='row'>";
    $output .= "<div class='col-md-6'>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='title_holder'>Title Holder (if not consignor):</label>";
    $output .= "<input type='text' name='title_holder' id='title_holder' class='form-control'>";
    $output .= "</div>";
    $output .= "</div>";

    $output .= "<div class='col-md-6'>";
    $output .= "<div class='form-group'>";
    $output .= "<label for='title_issue_date'>Title Issue Date:</label>";
    $output .= "<input type='date' name='title_issue_date' id='title_issue_date' class='form-control'>";
    $output .= "</div>";
    $output .= "</div>";
    $output .= "</div>";

    $output .= "<div class='form-check mt-3'>";
    $output .= "<input type='checkbox' name='title_in_possession' id='title_in_possession' value='1' class='form-check-input'>";
    $output .= "<label for='title_in_possession' class='form-check-label'>We have physical possession of the title</label>";
    $output .= "</div>";

    $output .= "<div class='alert alert-warning mt-3'>";
    $output .= "<strong>Important:</strong> For titled items, we must have the original title in our possession before sale, or proper transfer documentation completed.";
    $output .= "</div>";

    $output .= "</div>"; // End card-body
    $output .= "</div>"; // End title information card
    
    // Category selection
    $output .= "<div class='form-group'>";
    $output .= "<label for='category'>Category:</label>";
    $output .= "<select name='category' id='category' required class='form-control'>";
    $output .= "<option value='Standard'>Standard Equipment</option>";
    $output .= "<option value='Trailer'>Trailer</option>";
    $output .= "<option value='Tractors & Mowers'>Tractors & Mowers</option>";
    $output .= "<option value='Tools & Small Gear'>Tools & Small Gear</option>";
    $output .= "</select>";
    $output .= "</div>";
    
    // Pricing
    $output .= "<div class='form-group'>";
    $output .= "<label for='asking_price'>Asking Price ($):</label>";
    $output .= "<input type='number' name='asking_price' id='asking_price' step='0.01' required class='form-control'>";
    $output .= "</div>";
    
    $output .= "<div class='form-group'>";
    $output .= "<label for='min_price'>Minimum Acceptable Price ($):</label>";
    $output .= "<input type='number' name='min_price' id='min_price' step='0.01' required class='form-control'>";
    $output .= "</div>";
    
    // Authorization options
    $output .= "<div class='form-group'>";
    $output .= "<label for='rental_authorized'>Rental Authorized:</label>";
    $output .= "<select name='rental_authorized' id='rental_authorized' class='form-control'>";
    $output .= "<option value='0'>No</option>";
    $output .= "<option value='1'>Yes</option>";
    $output .= "</select>";
    $output .= "</div>";
    
    $output .= "<div class='form-group'>";
    $output .= "<label for='trade_authorized'>Trade Authorized:</label>";
    $output .= "<select name='trade_authorized' id='trade_authorized' class='form-control'>";
    $output .= "<option value='0'>No</option>";
    $output .= "<option value='1'>Yes</option>";
    $output .= "</select>";
    $output .= "</div>";
    
    // General notes
    $output .= "<div class='form-group'>";
    $output .= "<label for='notes'>Additional Notes:</label>";
    $output .= "<textarea name='notes' id='notes' class='form-control'></textarea>";
    $output .= "</div>";
    
    // Pickup/Delivery options
    $output .= "<div class='form-group'><label>Pickup Option:</label>
    <div class='form-check'><input class='form-check-input' type='checkbox' name='pickup_required' id='pickup_required'>
    <label class='form-check-label' for='pickup_required'>Free Pickup (within 30 miles, 16ft trailer max, profit ≥ \$75)</label></div>
    </div>";
    
    $output .= "<div class='form-group'><label>Pickup Phone</label>
    <input type='text' name='pickup_phone' class='form-control'></div>";
    
    $output .= "<div class='form-group'><label>Pickup Address</label>
    <textarea name='pickup_address' class='form-control'></textarea></div>";
    
    $output .= "<div class='form-group'><label>One-Way Mileage (for delivery/pickup)</label>
    <input type='number' step='0.1' name='mileage' class='form-control'></div>";
    
    $output .= "<div class='form-group'><label>Scheduled Pickup</label>
    <input type='datetime-local' name='scheduled_pickup' class='form-control'></div>";
    
    $output .= "<button type='submit' class='btn btn-primary'>Save Item</button>";
    $output .= "</form>";
    
    // JavaScript to toggle house inventory fields based on consignor selection
    // and to toggle title fields based on checkbox
    $output .= "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const consignorSelect = document.getElementById('consignor_id');
        const houseInventoryFields = document.getElementById('house_inventory_fields');
        
        consignorSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.text === 'House Inventory') {
                houseInventoryFields.style.display = 'block';
            } else {
                houseInventoryFields.style.display = 'none';
            }
        });
        
        // Initialize on page load
        const selectedOption = consignorSelect.options[consignorSelect.selectedIndex];
        if (selectedOption && selectedOption.text === 'House Inventory') {
            houseInventoryFields.style.display = 'block';
        }
    });
    
    function toggleTitleFields() {
        const titleCheckbox = document.getElementById('is_titled');
        const titleFields = document.getElementById('title_fields');
        
        if (titleCheckbox.checked) {
            titleFields.style.display = 'block';
        } else {
            titleFields.style.display = 'none';
        }
    }
    </script>";
    
    $conn->close();
    return $output;
}

// Main application logic
function handleRequest() {
    setupDatabase(); 
    $content = "";
    
    // Ensure tables exist
    
    $page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    
    
    // Set page override if editing item
    if ($action === 'edit_item' && isset($_GET['item_id'])) {
        $page = 'edit_item';
    }
    
    
    // ====================[ ACTION: SAVE TRADE ]====================
    if ($action === 'save_trade' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn = connectDB();
    
        $item_id = $_POST['item_id'];
        $trade_for = $_POST['trade_for'];
        $trade_value = $_POST['trade_value'];
        $broker_fee = $_POST['broker_fee'];
        $trade_notes = $_POST['trade_notes'];
        $trader_name = $_POST['trader_name'];
        $trader_phone = $_POST['trader_phone'];
        $trader_email = $_POST['trader_email'];
        $trader_address = $_POST['trader_address'];
        $trader_id = $_POST['trader_id'];
        $license_state = $_POST['license_state'];
    
        $stmt = $conn->prepare("INSERT INTO trades (
            item_id, trade_for, trade_value, broker_fee, trade_notes, trader_name, trader_phone, trader_email,
            trader_address, trader_id, license_state, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    if ($stmt === false) {
        die("<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>");
    }
    
        $stmt->bind_param(
            "isddsssssss",
            $item_id, $trade_for, $trade_value, $broker_fee, $trade_notes, $trader_name,
            $trader_phone, $trader_email, $trader_address, $trader_id, $license_state
        );
    
        if ($stmt->execute()) {
            $trade_id = $conn->insert_id;
            header("Location: ?action=trade_contract&trade_id={$trade_id}");
            exit;
        } else {
            $content .= "<div class='alert alert-danger'>Error saving trade: " . $conn->error . "</div>";
        }
    
        $stmt->close();
        $conn->close();
    }
    
    
    // Export PDF for completed rentals
    if ($action == 'export_completed_rentals_pdf') {
        require_once('MyPDF.php'); // Use your custom reusable TCPDF class
        $conn = connectDB();
        
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
        $pageNum = isset($_GET['p']) ? (int) $_GET['p'] : 1;
        $offset = ($pageNum - 1) * $limit;
        $sql = "
            SELECT r.*, i.description, i.make_model
            FROM rentals r
            JOIN items i ON r.item_id = i.id
            WHERE r.status = 'completed'
            ORDER BY r.returned_on DESC
            LIMIT $limit OFFSET $offset
        ";
        $result = $conn->query($sql);
        require_once('MyPDF.php'); // ? must come BEFORE using the class
        $pdf = new MyPDF();
        $pdf->SetCreator('Back2Work Equipment');
        $pdf->SetAuthor('Back2Work');
        $pdf->SetTitle('Completed Rentals');
        $pdf->SetMargins(10, 30, 10); // Top margin adjusted to make space for logo header
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->AddPage();
        
        $html = "<h2>Completed Rentals</h2>";
        $html .= "<table border='1' cellpadding='4'>
                    <thead>
                        <tr>
                            <th><strong>ID</strong></th>
                            <th><strong>Item</strong></th>
                            <th><strong>Renter</strong></th>
                            <th><strong>Returned</strong></th>
                            <th><strong>Inspection</strong></th>
                        </tr>
                    </thead><tbody>";
        while ($row = $result->fetch_assoc()) {
            $badge = $row['inspection_passed'] ? 'Passed' : 'Failed';
            $rental_id = "R-" . str_pad($row['id'], 5, "0", STR_PAD_LEFT);
            $returned = date('m/d/Y', strtotime($row['returned_on']));
            $html .= "<tr>
                        <td>{$rental_id}</td>
                        <td>{$row['description']}<br><small>{$row['make_model']}</small></td>
                        <td>{$row['renter_name']}</td>
                        <td>{$returned}</td>
                        <td>{$badge}</td>
                      </tr>";
        }
        $html .= "</tbody></table>";
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('completed_rentals.pdf', 'D');
        exit;
    }

    // Export PDF for rental history
    else if ($action === 'export_rental_history_pdf' && isset($_GET['consignor_id'])) {
        require_once('MyPDF.php'); // Use your custom reusable TCPDF class
        $consignor_id = (int) $_GET['consignor_id'];
        $conn = connectDB();
        $sql = "
            SELECT r.*, i.description, i.make_model, c.name AS consignor_name
            FROM rentals r
            JOIN items i ON r.item_id = i.id
            JOIN consignors c ON i.consignor_id = c.id
            WHERE i.consignor_id = ?
            ORDER BY r.rental_start DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $consignor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        require_once('MyPDF.php'); // ? must come BEFORE using the class
        $pdf = new MyPDF();
        $pdf->SetCreator('Back2Work Equipment');
        $pdf->SetTitle("Rental History - Consignor #{$consignor_id}");
        $pdf->SetHeaderData('', 0, "Rental History", "Consignor ID: {$consignor_id}", [0,64,255], [0,64,128]);
        $pdf->setHeaderFont(['helvetica', '', 12]);
        $pdf->setFooterFont(['helvetica', '', 10]);
        $pdf->SetMargins(10, 20, 10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->AddPage();
        $html = '<h2>Rental History</h2>';
        $html .= '<table border="1" cellspacing="0" cellpadding="4" width="100%">';
        $html .= '<thead><tr style="background-color:#f2f2f2;">
                    <th><b>Rental ID</b></th>
                    <th><b>Item</b></th>
                    <th><b>Rental Period</b></th>
                    <th><b>Renter</b></th>
                    <th><b>Daily Rate</b></th>
                    <th><b>Total</b></th>
                    <th><b>Status</b></th>
                  </tr></thead><tbody>';
        $total = 0;
        while ($row = $result->fetch_assoc()) {
            $rental_id = "R-" . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
            $status = ucfirst($row['status']);
            $period = date('m/d/Y', strtotime($row['rental_start'])) . ' - ' . date('m/d/Y', strtotime($row['rental_end']));
            $html .= "<tr>
                        <td>{$rental_id}</td>
                        <td>{$row['description']}<br><small>{$row['make_model']}</small></td>
                        <td>{$period}</td>
                        <td>{$row['renter_name']}<br><small>{$row['renter_contact']}</small></td>
                        <td>$" . number_format($row['daily_rate'], 2) . "</td>
                        <td>$" . number_format($row['total_amount'], 2) . "</td>
                        <td>{$status}</td>
                      </tr>";
            $total += $row['total_amount'];
        }
        $html .= "<tr style='font-weight:bold; background-color:#eee;'>
                    <td colspan='5'>Total Rental Income</td>
                    <td colspan='2'>$" . number_format($total, 2) . "</td>
                  </tr>";
        $html .= '</tbody></table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output("rental_history_consignor_{$consignor_id}.pdf", 'D');
        $conn->close();
        exit;
    }
    
    
    // If the action is record_sale, make sure we set page to record_sale
    if ($action == 'record_sale') {
        $page = 'record_sale';
    }
    
     // Handle form submissions
   // =======================[ ACTION: save_consignor ]=======================
// Handle saving a new consignor
if ($action == 'save_consignor' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $conn = connectDB();
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $payment_method = $_POST['preferred_payment_method'] ?? '';  // NOTE: correct field name from form
    $payment_details = $_POST['paypal_email'] ?? $_POST['cashapp_tag'] ?? $_POST['venmo_handle'] ?? $_POST['check_payable_to'] ?? '';
    $cc_number = $_POST['cc_number'] ?? '';
    $cc_expiry = $_POST['cc_expiry'] ?? '';
    $cc_last_four = substr($cc_number, -4);
    $agreement_on_file = isset($_POST['agreement_on_file']) ? 1 : 0;
    $abandonment_approved = isset($_POST['abandonment_approved']) ? 1 : 0;
    
    $sql = "INSERT INTO consignors (name, email, phone, address, payment_method, payment_details, cc_last_four, cc_expiry) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("ssssssss", $name, $email, $phone, $address, $payment_method, $payment_details, $cc_last_four, $cc_expiry);
        if ($stmt->execute()) {
            $content .= "<div class='alert alert-success'>Consignor saved successfully!</div>";
            $content .= "<a href='?page=consignors' class='btn btn-primary'>Back to Consignors</a>";
        } else {
            $content .= "<div class='alert alert-danger'>Error saving consignor: " . $stmt->error . "</div>";
        }
        $stmt->close();
    } else {
        $content .= "<div class='alert alert-danger'>Error preparing SQL: " . $conn->error . "</div>";
    }
    $conn->close();
}
    
if (isset($action) && $action === 'save_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = connectDB();
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    // Get all the basic fields
    $consignor_id = isset($_POST['consignor_id']) ? $_POST['consignor_id'] : 0;
    $description = isset($_POST['description']) ? $_POST['description'] : '';
    $make_model = isset($_POST['make_model']) ? $_POST['make_model'] : '';
    $serial_number = isset($_POST['serial_number']) ? $_POST['serial_number'] : '';
    $category = isset($_POST['category']) ? $_POST['category'] : '';
    $asking_price = isset($_POST['asking_price']) ? floatval($_POST['asking_price']) : 0;
    $min_price = isset($_POST['min_price']) ? floatval($_POST['min_price']) : 0;
    $condition_desc = isset($_POST['condition_desc']) ? $_POST['condition_desc'] : '';
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
    $rental_authorized = isset($_POST['rental_authorized']) ? intval($_POST['rental_authorized']) : 0;
    $trade_authorized = isset($_POST['trade_authorized']) ? intval($_POST['trade_authorized']) : 0;
    
    // Get pickup info
    $pickup_required = isset($_POST['pickup_required']) ? 1 : 0;
    $pickup_phone = isset($_POST['pickup_phone']) ? $_POST['pickup_phone'] : '';
    $pickup_address = isset($_POST['pickup_address']) ? $_POST['pickup_address'] : '';
    $mileage = isset($_POST['mileage']) ? floatval($_POST['mileage']) : 0;
    
    // Fix datetime format for scheduled pickup
    $scheduled_pickup = null;
    if (!empty($_POST['scheduled_pickup'])) {
        try {
            $pickup_date = new DateTime($_POST['scheduled_pickup']);
            $scheduled_pickup = $pickup_date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            error_log("Failed to parse pickup date: " . $_POST['scheduled_pickup'] . " - " . $e->getMessage());
        }
    }
    
    // Pickup cancellation
    $pickup_canceled = isset($_POST['cancel_pickup']) ? 1 : 0;
    $pickup_canceled_reason = isset($_POST['pickup_canceled_reason']) ? $_POST['pickup_canceled_reason'] : '';
    $pickup_canceled_at = $pickup_canceled ? date('Y-m-d H:i:s') : null;
    
    // Auto-clear cancellation if a new pickup is scheduled and not being canceled again
    if (!empty($scheduled_pickup) && empty($_POST['cancel_pickup'])) {
        $pickup_canceled = 0;
        $pickup_canceled_reason = null;
        $pickup_canceled_at = null;
    }
    
    // Get new fields
    $purchase_source = isset($_POST['purchase_source']) ? $_POST['purchase_source'] : '';
    $purchase_price = isset($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : 0;
    $date_acquired = isset($_POST['date_acquired']) && !empty($_POST['date_acquired']) ? $_POST['date_acquired'] : null;
    $hours_used = isset($_POST['hours_used']) ? intval($_POST['hours_used']) : null;
    $maintenance_history = isset($_POST['maintenance_history']) ? $_POST['maintenance_history'] : '';
    $known_issues = isset($_POST['known_issues']) ? $_POST['known_issues'] : '';
    $wear_description = isset($_POST['wear_description']) ? $_POST['wear_description'] : '';
    $last_maintenance_date = isset($_POST['last_maintenance_date']) && !empty($_POST['last_maintenance_date']) ? $_POST['last_maintenance_date'] : null;
    
    // Get title information
    $is_titled = isset($_POST['is_titled']) ? 1 : 0;
    $title_number = isset($_POST['title_number']) ? $_POST['title_number'] : '';
    $title_state = isset($_POST['title_state']) ? $_POST['title_state'] : '';
    $vin = isset($_POST['vin']) ? $_POST['vin'] : '';
    $title_status = isset($_POST['title_status']) ? $_POST['title_status'] : '';
    $title_holder = isset($_POST['title_holder']) ? $_POST['title_holder'] : '';
    $title_issue_date = isset($_POST['title_issue_date']) && !empty($_POST['title_issue_date']) ? $_POST['title_issue_date'] : null;
    $title_in_possession = isset($_POST['title_in_possession']) ? 1 : 0;
    
    // Handle House Inventory correctly
    if (isset($_POST['consignor_id']) && ($_POST['consignor_id'] === 'house_inventory' || $_POST['consignor_id'] === '' || $_POST['consignor_id'] === NULL)) {
        $consignor_id = NULL;
        $owned_by_company = 1;
        $status = 'house inventory';
    } else {
        $consignor_id = (int)$_POST['consignor_id'];
        $owned_by_company = 0;
        $status = isset($_POST['status']) ? $_POST['status'] : 'active';
        
        // Double check if this is house inventory based on consignor name
        if (!empty($consignor_id)) {
            $consignorStmt = $conn->prepare("SELECT name FROM consignors WHERE id = ?");
            if ($consignorStmt) {
                $consignorStmt->bind_param("i", $consignor_id);
                $consignorStmt->execute();
                $result = $consignorStmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $consignor = $result->fetch_assoc();
                    if ($consignor['name'] === 'House Inventory') {
                        $owned_by_company = 1;
                    }
                }
                $consignorStmt->close();
            }
        }
    }
    
    if ($id > 0) {
        // === UPDATE ===
        $sql = "UPDATE items SET 
            description = ?, make_model = ?, serial_number = ?, condition_desc = ?, 
            category = ?, asking_price = ?, min_price = ?, consignor_id = ?, 
            rental_authorized = ?, is_trade_authorized = ?, status = ?, notes = ?, 
            pickup_required = ?, pickup_phone = ?, pickup_address = ?, 
            mileage = ?, scheduled_pickup = ?, pickup_canceled = ?, pickup_canceled_reason = ?, pickup_canceled_at = ?, 
            owned_by_company = ?, purchase_source = ?, purchase_price = ?, date_acquired = ?,
            hours_used = ?, maintenance_history = ?, known_issues = ?, wear_description = ?, last_maintenance_date = ?,
            is_titled = ?, title_number = ?, title_state = ?, vin = ?, title_status = ?, 
            title_holder = ?, title_issue_date = ?, title_in_possession = ?
            WHERE id = ?";

        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            die("Error preparing statement: " . $conn->error);
        }
        
        $stmt->bind_param(
            "sssssddiissiiissdissisdisissiissssii",
            $description, $make_model, $serial_number, $condition_desc,
            $category, $asking_price, $min_price, $consignor_id,
            $rental_authorized, $trade_authorized, $status, $notes,
            $pickup_required, $pickup_phone, $pickup_address,
            $mileage, $scheduled_pickup, $pickup_canceled, $pickup_canceled_reason, $pickup_canceled_at,
            $owned_by_company, $purchase_source, $purchase_price, $date_acquired,
            $hours_used, $maintenance_history, $known_issues, $wear_description, $last_maintenance_date,
            $is_titled, $title_number, $title_state, $vin, $title_status,
            $title_holder, $title_issue_date, $title_in_possession,
            $id
        );

        if ($stmt->execute()) {
            $message = "Item updated successfully.";
            header("Location: ?page=inventory&msg=updated");
            exit;
        } else {
            $message = "Error updating item: " . $conn->error;
            header("Location: ?page=edit_item&id={$id}&error=update_failed");
            exit;
        }
    } else {
        // === INSERT ===
        $sql = "INSERT INTO items (
            description, make_model, serial_number, condition_desc, category,
            asking_price, min_price, consignor_id, date_received,
            rental_authorized, is_trade_authorized, notes, owned_by_company, status,
            pickup_required, pickup_phone, pickup_address, 
            mileage, scheduled_pickup, pickup_canceled, pickup_canceled_reason, pickup_canceled_at,
            purchase_source, purchase_price, date_acquired, hours_used, maintenance_history, 
            known_issues, wear_description, last_maintenance_date,
            is_titled, title_number, title_state, vin, title_status, 
            title_holder, title_issue_date, title_in_possession
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            die("Error preparing statement: " . $conn->error);
        }
        
        $stmt->bind_param(
            "sssssddiisiisiiisdiissdissssiisssi",
            $description, $make_model, $serial_number, $condition_desc, $category,
            $asking_price, $min_price, $consignor_id,
            $rental_authorized, $trade_authorized, $notes, $owned_by_company, $status,
            $pickup_required, $pickup_phone, $pickup_address,
            $mileage, $scheduled_pickup, $pickup_canceled, $pickup_canceled_reason, $pickup_canceled_at,
            $purchase_source, $purchase_price, $date_acquired, $hours_used, $maintenance_history,
            $known_issues, $wear_description, $last_maintenance_date,
            $is_titled, $title_number, $title_state, $vin, $title_status,
            $title_holder, $title_issue_date, $title_in_possession
        );

        if ($stmt->execute()) {
            $item_id = $conn->insert_id;
            $message = "Item added successfully. <a href='?action=generate_agreement&item_id={$item_id}' target='_blank'>Generate Agreement - FOR CONSIGNORS</a>";
            header("Location: ?page=inventory&msg=added");
            exit;
        } else {
            $message = "Error adding item: " . $conn->error;
            header("Location: ?page=add_item&error=save_failed");
            exit;
        }
    }

    $stmt->close();
    $conn->close();
}


if ($action === 'update_item' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = connectDB();
    $item_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    if (empty($item_id)) {
        header("Location: ?page=inventory&error=invalid_item");
        exit;
    }
    
    // Get all the basic fields
    $consignor_id = isset($_POST['consignor_id']) ? $_POST['consignor_id'] : 0;
    $description = isset($_POST['description']) ? $conn->real_escape_string($_POST['description']) : '';
    $make_model = isset($_POST['make_model']) ? $conn->real_escape_string($_POST['make_model']) : '';
    $serial_number = isset($_POST['serial_number']) ? $conn->real_escape_string($_POST['serial_number']) : '';
    $category = isset($_POST['category']) ? $conn->real_escape_string($_POST['category']) : '';
    $asking_price = isset($_POST['asking_price']) ? floatval($_POST['asking_price']) : 0;
    $min_price = isset($_POST['min_price']) ? floatval($_POST['min_price']) : 0;
    $condition_desc = isset($_POST['condition_desc']) ? $conn->real_escape_string($_POST['condition_desc']) : '';
    $notes = isset($_POST['notes']) ? $conn->real_escape_string($_POST['notes']) : '';
    $status = isset($_POST['status']) ? $conn->real_escape_string($_POST['status']) : 'active';
    $rental_authorized = isset($_POST['rental_authorized']) ? intval($_POST['rental_authorized']) : 0;
    $trade_authorized = isset($_POST['trade_authorized']) ? intval($_POST['trade_authorized']) : 0;
    
    // Get pickup info
    $pickup_required = isset($_POST['pickup_required']) ? 1 : 0;
    $pickup_phone = isset($_POST['pickup_phone']) ? $conn->real_escape_string($_POST['pickup_phone']) : '';
    $pickup_address = isset($_POST['pickup_address']) ? $conn->real_escape_string($_POST['pickup_address']) : '';
    $mileage = isset($_POST['mileage']) ? floatval($_POST['mileage']) : null;
    
    // Fix datetime format
    $scheduled_pickup = null;
    if (!empty($_POST['scheduled_pickup'])) {
        try {
            $pickup_date = new DateTime($_POST['scheduled_pickup']);
            $scheduled_pickup = $pickup_date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            error_log("Failed to parse scheduled_pickup: " . $_POST['scheduled_pickup']);
        }
    }
    
    // Get new fields
    $purchase_source = isset($_POST['purchase_source']) ? $conn->real_escape_string($_POST['purchase_source']) : '';
    $purchase_price = isset($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : 0;
    $date_acquired = isset($_POST['date_acquired']) && !empty($_POST['date_acquired']) ? $_POST['date_acquired'] : null;
    $hours_used = isset($_POST['hours_used']) ? intval($_POST['hours_used']) : null;
    $maintenance_history = isset($_POST['maintenance_history']) ? $conn->real_escape_string($_POST['maintenance_history']) : '';
    $known_issues = isset($_POST['known_issues']) ? $conn->real_escape_string($_POST['known_issues']) : '';
    $wear_description = isset($_POST['wear_description']) ? $conn->real_escape_string($_POST['wear_description']) : '';
    $last_maintenance_date = isset($_POST['last_maintenance_date']) && !empty($_POST['last_maintenance_date']) ? $_POST['last_maintenance_date'] : null;
    
    // Get title information
    $is_titled = isset($_POST['is_titled']) ? 1 : 0;
    $title_number = isset($_POST['title_number']) ? $conn->real_escape_string($_POST['title_number']) : '';
    $title_state = isset($_POST['title_state']) ? $conn->real_escape_string($_POST['title_state']) : '';
    $vin = isset($_POST['vin']) ? $conn->real_escape_string($_POST['vin']) : '';
    $title_status = isset($_POST['title_status']) ? $conn->real_escape_string($_POST['title_status']) : '';
    $title_holder = isset($_POST['title_holder']) ? $conn->real_escape_string($_POST['title_holder']) : '';
    $title_issue_date = isset($_POST['title_issue_date']) && !empty($_POST['title_issue_date']) ? $_POST['title_issue_date'] : null;
    $title_in_possession = isset($_POST['title_in_possession']) ? 1 : 0;
    
    // Handle pickup cancellation
    $pickup_canceled = isset($_POST['cancel_pickup']) ? 1 : 0;
    $pickup_canceled_reason = isset($_POST['pickup_canceled_reason']) ? $conn->real_escape_string($_POST['pickup_canceled_reason']) : '';
    $pickup_canceled_at = $pickup_canceled ? date('Y-m-d H:i:s') : null;
    
    // Handle House Inventory correctly
    if (isset($_POST['consignor_id']) && ($_POST['consignor_id'] === 'house_inventory' || $_POST['consignor_id'] === '' || $_POST['consignor_id'] === NULL)) {
        $consignor_id = NULL;
        $owned_by_company = 1;
        $status = 'house inventory';
    } else {
        $owned_by_company = 0;
        
        // Get consignor name to check if it's house inventory
        if (!empty($consignor_id)) {
            $consignorStmt = $conn->prepare("SELECT name FROM consignors WHERE id = ?");
            if ($consignorStmt) {
                $consignorStmt->bind_param("i", $consignor_id);
                $consignorStmt->execute();
                $result = $consignorStmt->get_result();
                if ($result && $result->num_rows > 0) {
                    $consignor = $result->fetch_assoc();
                    if ($consignor['name'] === 'House Inventory') {
                        $owned_by_company = 1;
                    }
                }
                $consignorStmt->close();
            }
        }
    }
    
    // Use prepared statement for safety
    $sql = "UPDATE items SET 
            description = ?, 
            make_model = ?, 
            serial_number = ?, 
            condition_desc = ?, 
            category = ?, 
            asking_price = ?, 
            min_price = ?, 
            consignor_id = ?, 
            status = ?, 
            owned_by_company = ?, 
            rental_authorized = ?, 
            is_trade_authorized = ?, 
            notes = ?,
            pickup_required = ?,
            pickup_phone = ?, 
            pickup_address = ?, 
            mileage = ?, 
            scheduled_pickup = ?, 
            pickup_canceled = ?, 
            pickup_canceled_reason = ?, 
            pickup_canceled_at = ?, 
            purchase_source = ?, 
            purchase_price = ?, 
            date_acquired = ?, 
            hours_used = ?, 
            maintenance_history = ?, 
            known_issues = ?, 
            wear_description = ?, 
            last_maintenance_date = ?,
            is_titled = ?,
            title_number = ?,
            title_state = ?,
            vin = ?,
            title_status = ?,
            title_holder = ?,
            title_issue_date = ?,
            title_in_possession = ?
            WHERE id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error preparing statement: " . $conn->error);
    }
    
    // Handle null values correctly
    $mileageParam = $mileage !== null ? $mileage : null;
    $scheduledPickupParam = $scheduled_pickup !== null ? $scheduled_pickup : null;
    $pickupCanceledAtParam = $pickup_canceled_at !== null ? $pickup_canceled_at : null;
    $dateAcquiredParam = $date_acquired !== null ? $date_acquired : null;
    $hoursUsedParam = $hours_used !== null ? $hours_used : null;
    $lastMaintenanceDateParam = $last_maintenance_date !== null ? $last_maintenance_date : null;
    $titleIssueDateParam = $title_issue_date !== null ? $title_issue_date : null;
    
    $stmt->bind_param(
        "sssssddisissiissdsissdssississssssi",
        $description, $make_model, $serial_number, $condition_desc, $category,
        $asking_price, $min_price, $consignor_id, $status, $owned_by_company,
        $rental_authorized, $trade_authorized, $notes, $pickup_required,
        $pickup_phone, $pickup_address, $mileageParam, $scheduledPickupParam,
        $pickup_canceled, $pickup_canceled_reason, $pickupCanceledAtParam,
        $purchase_source, $purchase_price, $dateAcquiredParam, $hoursUsedParam,
        $maintenance_history, $known_issues, $wear_description, $lastMaintenanceDateParam,
        $is_titled, $title_number, $title_state, $vin, $title_status,
        $title_holder, $titleIssueDateParam, $title_in_possession,
        $item_id
    );

    if ($stmt->execute()) {
        header("Location: ?page=inventory&msg=updated");
        exit;
    } else {
        $content = "<div class='alert alert-danger'>Update failed: " . $conn->error . "</div>";
        header("Location: ?page=edit_item&id={$item_id}&error=update_failed");
        exit;
    }

    $stmt->close();
    $conn->close();
}

    if ($action == 'record_sale' && isset($_GET['item_id'])) {
        $item_id = (int) $_GET['item_id'];
        $conn = connectDB();
        $stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();
        if ($item) {
            ob_start();
            ?>
            <h2>Record Sale for: <?= htmlspecialchars($item['description']) ?></h2>
            <form method="post" action="?action=save_sale">
                <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <div class="form-group">
                    <input type="date" name="sale_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Sale Price ($):</label>
                    <input type="number" step="0.01" name="sale_price" class="form-control" value="<?= htmlspecialchars($item['asking_price']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Buyer Name:</label>
                    <input type="text" name="buyer_name" class="form-control">
                </div>
                <div class="form-group">
                    <label>Buyer Contact:</label>
                    <textarea name="buyer_contact" class="form-control"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save Sale</button>
            </form>
            <?php
            $content = isset($content) ? $content : '';
            $content .= ob_get_clean();
        } else {
            $message = "<p>Item not found.</p>";
        }
        $stmt->close();
        $conn->close();
    }
    if ($action == 'save_sale' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $conn = connectDB();

    $item_id = (int) $_POST['item_id'];
    $sale_date = $_POST['sale_date'];
    $sale_price = (float) $_POST['sale_price'];
    $buyer_name = trim($_POST['buyer_name'] ?? '');
    $buyer_contact = trim($_POST['buyer_contact'] ?? '');
    $delivery_method = $_POST['delivery_method'] ?? 'pickup';
    $scheduled_time = $_POST['scheduled_time'] ?? null;
    $mileage = isset($_POST['mileage']) ? floatval($_POST['mileage']) : 0.0;

    // Fetch item to determine ownership
    $item_stmt = $conn->prepare("SELECT owned_by_company FROM items WHERE id = ?");
    $item_stmt->bind_param("i", $item_id);
    $item_stmt->execute();
    $item_result = $item_stmt->get_result();
    $item = $item_result->fetch_assoc();

    if (!$item) {
        $content .= "<div class='alert alert-danger'>Item not found during save.</div>";
        return;
    }

    // Calculate commission and profit
    if (empty($item['owned_by_company'])) {
        $commission_rate = 0.15;
        $commission_amount = $sale_price * $commission_rate;
        if (($sale_price - $commission_amount) < 10) {
            $commission_amount = $sale_price - 10;
        }
        if ($commission_amount > 500) {
            $commission_amount = 500;
        }
        $profit = $sale_price - $commission_amount;
    } else {
        $commission_rate = 0.00;
        $commission_amount = 0.00;
        $profit = $sale_price;
    }

    $sales_tax = $sale_price * 0.0825;

    $stmt = $conn->prepare("INSERT INTO sales (item_id, sale_date, sale_price, buyer_name, buyer_phone, buyer_address, delivery_method, scheduled_time, mileage, commission_rate, commission_amount, sale_tax, profit) VALUES (?, ?, ?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isdsssdddddd", 
        $item_id, $sale_date, $sale_price, $buyer_name, $buyer_contact, 
        $delivery_method, $scheduled_time, $mileage,
        $commission_rate, $commission_amount, $sales_tax, $profit
    );

    if ($stmt->execute()) {
        $update = $conn->prepare("UPDATE items SET status = 'sold' WHERE id = ?");
        $update->bind_param("i", $item_id);
        $update->execute();
        $message = "<div class='alert alert-success'>Sale recorded successfully.</div>";
    } else {
        $message = "<div class='alert alert-danger'>Error saving sale: " . $conn->error . "</div>";
    }

    $stmt->close();
    $conn->close();
}
    
    if ($action == 'update_consignor' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $conn = connectDB();
    
        $id = $_POST['id'] ?? 0;
        $name = $_POST['name'] ?? '';
    
        // Fallback if name is empty
        if (empty($name)) {
            $fetchStmt = $conn->prepare("SELECT name FROM consignors WHERE id = ?");
            $fetchStmt->bind_param("i", $id);
            $fetchStmt->execute();
            $fetchResult = $fetchStmt->get_result();
            if ($row = $fetchResult->fetch_assoc()) {
                $name = $row['name'];
            }
            $fetchStmt->close();
        }
    
        // Form fields
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        $payment_method = $_POST['payment_method'] ?? '';
        $payment_details = $_POST['payment_details'] ?? '';
        $paypal_email = $_POST['paypal_email'] ?? '';
        $cashapp_tag = $_POST['cashapp_tag'] ?? '';
        $venmo_handle = $_POST['venmo_handle'] ?? '';
        $check_payable_to = $_POST['check_payable_to'] ?? '';
        $cc_number = $_POST['cc_number'] ?? '';
        $cc_expiry = $_POST['cc_expiry'] ?? '';
        $cc_cvv = $_POST['cc_cvv'] ?? '';
        $agreement_on_file = isset($_POST['agreement_on_file']) ? 1 : 0;
        $abandonment_approved = isset($_POST['abandonment_approved']) ? 1 : 0;
    
        // Handle driver's license upload
        $license_file_path = null;
        if (isset($_FILES['license_file']) && $_FILES['license_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = 'uploads/licenses/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = time() . '_' . basename($_FILES['license_file']['name']);
            $targetPath = $uploadDir . $filename;
    
            if (move_uploaded_file($_FILES['license_file']['tmp_name'], $targetPath)) {
                $license_file_path = $targetPath;
            }
        }
    
        // Prepare SQL and bind depending on license file upload
        if ($license_file_path !== null) {
            $sql = "UPDATE consignors SET 
                name = ?, email = ?, phone = ?, address = ?, 
                payment_method = ?, payment_details = ?, 
                paypal_email = ?, cashapp_tag = ?, venmo_handle = ?, check_payable_to = ?, 
                cc_number = ?, cc_expiry = ?, cc_cvv = ?, 
                agreement_on_file = ?, abandonment_approved = ?, license_file = ?
                WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                die("<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>");
            }
            
            $stmt->bind_param("sssssssssssssissi", 
    $name, $email, $phone, $address, $payment_method, $payment_details,
    $paypal_email, $cashapp_tag, $venmo_handle, $check_payable_to,
    $cc_number, $cc_expiry, $cc_cvv, 
    $agreement_on_file, $abandonment_approved,
    $license_file_path, $id
);

        } else {
            $sql = "UPDATE consignors SET 
                name = ?, email = ?, phone = ?, address = ?, 
                payment_method = ?, payment_details = ?, 
                paypal_email = ?, cashapp_tag = ?, venmo_handle = ?, check_payable_to = ?, 
                cc_number = ?, cc_expiry = ?, cc_cvv = ?, 
                agreement_on_file = ?, abandonment_approved = ?
                WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "sssssssssssssii", 
                $name, $email, $phone, $address,
                $payment_method, $payment_details,
                $paypal_email, $cashapp_tag, $venmo_handle, $check_payable_to,
                $cc_number, $cc_expiry, $cc_cvv,
                $agreement_on_file, $abandonment_approved, $id
            );
        }
    
        // Run update
        if ($stmt && $stmt->execute()) {
            echo "<div class='alert alert-success'>Consignor updated successfully.</div>";
        } else {
            echo "<div class='alert alert-danger'>Error updating consignor: " . htmlspecialchars($stmt->error ?? $conn->error) . "</div>";
        }
    
        $stmt->close();
    
        // Handle agreement upload (already correct)
        $uploaded_file_path = null;
        if (isset($_FILES['agreement_file']) && $_FILES['agreement_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/agreements/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
    
            $original_ext = pathinfo($_FILES['agreement_file']['name'], PATHINFO_EXTENSION);
            $filename = 'agreement_' . $id . '_' . time() . '.' . strtolower($original_ext);
            $target_path = $upload_dir . $filename;
    
            if (move_uploaded_file($_FILES['agreement_file']['tmp_name'], $target_path)) {
                $uploaded_file_path = 'uploads/agreements/' . $filename;
    
                $stmt_agreement = $conn->prepare("UPDATE consignors SET agreement_file = ? WHERE id = ?");
                $stmt_agreement->bind_param("si", $uploaded_file_path, $id);
                $stmt_agreement->execute();
                $stmt_agreement->close();
            }
        }
    
        $conn->close();
    }

   if ($action === 'delete_customer' && isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $conn = connectDB();
    
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->bind_param("i", $id);
    
        if ($stmt->execute()) {
            header("Location: ?page=customers&msg=deleted");
            exit;
        } else {
            $content .= "<div class='alert alert-danger'>Error deleting customer: " . $conn->error . "</div>";
        }
    
        $conn->close();
    }
    
    
    
    // Display appropriate page content
    $content = "";
    
    if (isset($message)) {
        $content .= "<div class='alert alert-info'>{$message}</div>";
    }
    
    $action = $_GET['action'] ?? '';
$page = $_GET['page'] ?? 'dashboard';
if (isset($action) && $action === 'generate_blank_agreement') {
    require_once('MyPDF.php');
    $pdf = new MyPDF();
$pdf->setPrintHeader(false); // Disable default header
$pdf->SetCreator('Back2Work Equipment');
$pdf->SetAuthor('Back2Work');
$pdf->SetTitle('Consignment Agreement');

// Adjust top margin as needed
$pdf->SetTopMargin(10);
$pdf->AddPage();
$pdf->Ln(5); // Reduce from 30 to 10 to pull everything up

// HEADER LINES
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, 'BACK2WORK EQUIPMENT', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, '10460 US Hwy 79 E, Oakwood, TX 75855 | (903) 721-5544', 0, 1, 'C');
$pdf->Ln(3); // <-- Moves the line below downward just a bit
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Consignment Agreement', 0, 1, 'C');

// Remove/reduce spacing between this block and the date
$pdf->Ln(4);

    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 10, 'Date: ' . date('m/d/Y'), 0, 1, 'L');

    if (isset($_GET['consignor_id'])) {
        $conn = connectDB();
        $stmt = $conn->prepare("SELECT * FROM consignors WHERE id = ?");
        $stmt->bind_param("i", $_GET['consignor_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $consignor = $result->fetch_assoc();
            $pdf->MultiCell(0, 6, "Consignor Name: {$consignor['name']}", 0, 'L');
            $pdf->MultiCell(0, 6, "Phone: {$consignor['phone']}", 0, 'L');
            $pdf->MultiCell(0, 6, "Email: {$consignor['email']}", 0, 'L');
            $pdf->MultiCell(0, 6, "Address: {$consignor['address']}", 0, 'L');
        }
        $conn->close();
    } else {
        $pdf->MultiCell(0, 6, "Consignor Name: _________________________", 0, 'L');
        $pdf->MultiCell(0, 6, "Phone: _________________________", 0, 'L');
        $pdf->MultiCell(0, 6, "Email: _________________________", 0, 'L');
        $pdf->MultiCell(0, 6, "Address: _________________________", 0, 'L');
    }

    $pdf->Ln(4);

    $agreement = <<<EOD
This agreement is made between Back2Work Equipment ("Consignee") and the undersigned equipment owner ("Consignor"). By signing this document, the Consignor agrees to the following terms:

1. ITEM INFORMATION
Description of Item: ________________________________
Make/Model: _______________________________________
Condition: _________________________________________
Asking Price: \$____________
Minimum Price: \$____________

2. CONSIGNMENT PERIOD
The item will be listed for 60 days from the date of this agreement. If not sold, options include price change, extension, or pickup. Lack of response within 7 days of contact may result in the item being considered abandoned.

3. COMMISSION STRUCTURE
Commission: ______% OR Flat Fee: \$______
Minimum Commission: \$______

4. OWNERSHIP & LEGAL CLAIMS
The Consignor certifies they own the item, it is free from liens, and no title exists. The Consignor agrees to indemnify Back2Work Equipment for any disputes.

5. ABANDONMENT & STORAGE
Uncollected items may incur a \$____ storage/removal fee charged to the card on file after no response.

6. CREDIT CARD AUTHORIZATION
Card may be charged for abandonment.
Initials: ________    Date: ____________

7. LIABILITY
Consignee is not responsible for theft, damage, or mechanical failure. Items are consigned as-is.

8. PAYMENT TO CONSIGNOR
Payee Name: ________________________
Payment Method: Check / Zelle / PayPal / Other: __________
Payment Contact Info: ___________________________

9. TITLE TRANSFERS
A copy of this agreement will be signed by the buyer at the time of sale and provided to the consignor (owner) for their records. This agreement serves as documentation that the item was sold under consignment by Back2Work Equipment.

Back2Work Equipment does not participate in or facilitate the transfer of any vehicle, trailer, or equipment titles. It is the sole responsibility of the buyer and seller (consignor) to complete any necessary title transfers or legal ownership changes.

Back2Work Equipment assumes no liability for delays, omissions, or disputes related to title status, registration, taxes, or ownership after the sale is completed.

Law Enforcement Cooperation Policy:
Back2Work Equipment maintains detailed records of all sales, rentals, and consignments, including customer identification and signed agreements. In the event of an investigation involving stolen property, fraud, or disputed ownership, we cooperate fully with law enforcement.

While we do not grant outside access to our internal systems, we will promptly provide transaction records, uploaded identification, and related documentation upon lawful request from an authorized officer.

It is our policy to retain a copy of the buyer’s or renter’s valid, government-issued photo ID for every transaction involving ownership transfer, consignment, or equipment rental.

SIGNATURES
Consignor Name: _________________________
Signature: ______________________________
Date: __________

Buyer Name: _________________________
Signature: ______________________________
Date: __________

Consignee: Back2Work Equipment
Signature: ______________________________
Date: __________
EOD;

    $pdf->MultiCell(0, 6, $agreement, 0, 'L');
    $pdf->Output("consignment_agreement.pdf", 'I');
    exit;
}


if (isset($_GET['action'])) {
    $action = $_GET['action'];
    switch ($action) {
        case 'save_promotion':
            $conn = connectDB();
            // Get form inputs safely
            $item_id = (int)$_POST['item_id'];
            $platform = mysqli_real_escape_string($conn, $_POST['platform']);
            $promotion_type = mysqli_real_escape_string($conn, $_POST['promotion_type']);
            $cost = (float)$_POST['cost'];
            $billing_method = mysqli_real_escape_string($conn, $_POST['billing_method']);
            // Insert into promotions table
            $sql = "INSERT INTO promotions (item_id, platform, promotion_date, promotion_type, cost, billing_method)
                    VALUES ($item_id, '$platform', NOW(), '$promotion_type', $cost, '$billing_method')";
            if ($conn->query($sql)) {
                $_SESSION['success_message'] = "Promotion saved successfully.";
            } else {
                $_SESSION['error_message'] = "Error saving promotion: " . $conn->error;
            }
            $conn->close();
            // Redirect back to Add Promotion page or a Promotions list page
            header("Location: ?page=add_promotion");
            exit;
            break;

            $content = '';

                                

            case 'take_ownership':
                if (isset($_GET['item_id']) && is_numeric($_GET['item_id'])) {
                    $conn = connectDB();
                    $item_id = (int)$_GET['item_id'];
            
                    // Step 1: Get abandonment_date and consignor_id
                    $stmt = $conn->prepare("SELECT abandonment_date, consignor_id FROM items WHERE id = ?");
                    $stmt->bind_param("i", $item_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
            
                    if ($result && $result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        $abandonment_date = $row['abandonment_date'];
                        $consignor_id = (int)$row['consignor_id'];
            
                        // Step 2: Insert abandonment record
                        $reason = "Consignor failed to retrieve item within 7-day grace period.";
                        $insert = $conn->prepare("INSERT INTO abandonments (item_id, abandonment_date, reason, consignor_id) VALUES (?, ?, ?, ?)");
                        $insert->bind_param("issi", $item_id, $abandonment_date, $reason, $consignor_id);
            
                        if (!$insert->execute()) {
                            echo "Failed to insert abandonment record: " . $insert->error;
                            exit;
                        }
            
                        // Step 3: Update the item
                        $update = $conn->prepare("UPDATE items 
                            SET consignor_id = NULL, 
                                owned_by_company = 1, 
                                status = 'house inventory' 
                            WHERE id = ?");
                        $update->bind_param("i", $item_id);
            
                        if (!$update->execute()) {
                            echo "? Failed to update item: " . $update->error;
                            exit;
                        }
            
                        $_SESSION['success_message'] = "? Item successfully taken into house inventory.";
                    } else {
                        $_SESSION['error_message'] = "? Item not found.";
                    }
            
                    $conn->close();
                } else {
                    $_SESSION['error_message'] = "? Invalid item ID.";
                }
            
                header("Location: ?page=inventory");
                exit;
            
        // You can add more cases here like save_item, issue_refund, etc.
    } // <-- end switch ($action)
} // <-- end if isset($_GET['action'])
        switch ($page) {
            case 'dashboard':
                $content .= displayDashboard($page);
            
                // Append commission summary directly here
$content .= '
<div class="card mt-4">
    <div class="card-header">
        <a class="btn btn-link" data-toggle="collapse" href="#commissionSummary" role="button" aria-expanded="false" aria-controls="commissionSummary">
            Commission Rate Summary (Click to Expand)
        </a>
    </div>
    <div class="collapse" id="commissionSummary">
        <div class="card-body">

            <h5>Tiered Commission Rates (Sales)</h5>
            <ul>
                <li><strong>$0 - $250:</strong> 25% commission</li>
                <li><strong>$251 - $1,000:</strong> 10% commission</li>
                <li><strong>$1,001 - $5,000:</strong> 8% commission</li>
                <li><strong>$5,001 and up:</strong> 6% commission</li>
            </ul>
            <p class="text-muted"><em>Commission is based on the final sale price of the item.</em></p>

            <hr>

            <h5>Trade Broker Fees</h5>
            <ul>
                <li><strong>$0 - $500:</strong> $50 broker fee</li>
                <li><strong>$501 - $1,000:</strong> $100 broker fee</li>
                <li><strong>$1,001 - $2,000:</strong> $150 broker fee</li>
                <li><strong>$2,001 and up:</strong> $200+ broker fee (negotiable)</li>
            </ul>
            <p class="text-muted"><em>Broker fees help cover time spent locating and arranging trades. Actual fee may vary based on trade complexity.</em></p>

            <hr>

            <h5>Rental Fee Split</h5>
            <p><strong>Consignor receives:</strong> 40% of each rental</p>
            <p><strong>Back2Work Equipment retains:</strong> 60% for marketing, handling, and administration.</p>
            <p class="text-muted"><em>Split applies to each rental transaction once item uploads and rental features are live.</em></p>

            <hr>

            <h6 class="mt-4">⚠️ Add-Ons & Notes</h6>
            <ul>
                <li><strong>Damage Deposit:</strong> $100–$500 depending on item</li>
                <li><strong>Delivery/Pickup:</strong> $50 flat fee (if eligible)</li>
                <li><strong>Fuel Surcharge:</strong> Applies if returned unfilled</li>
                <li><strong>Cleaning Fee:</strong> $25–$100 if returned dirty</li>
                <li><strong>Late Fee:</strong> 1.5× daily rate after grace period</li>
            </ul>
            <p class="text-muted"><em>Rates are suggested. Adjust based on condition, demand, and value. Delivery subject to approval.</em></p>

            <h5>Rental Rates</h5>

            <h6 class="mt-3">🚧 Construction & Landscaping Equipment</h6>
            <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr><th>Item</th><th>Daily</th><th>Weekend</th><th>Weekly</th><th>Monthly</th><th class="table-warning">Deposit</th></tr>
                </thead>
                <tbody>
                    <tr><td>Mini Excavator</td><td>$175</td><td>$300</td><td>$850</td><td>$2,500</td><td class="table-warning">$500</td></tr>
                    <tr><td>Skid Steer / Bobcat</td><td>$200</td><td>$350</td><td>$950</td><td>$2,800</td><td class="table-warning">$500</td></tr>
                    <tr><td>Trencher</td><td>$125</td><td>$200</td><td>$650</td><td>$1,800</td><td class="table-warning">$300</td></tr>
                    <tr><td>Plate Compactor</td><td>$65</td><td>$100</td><td>$350</td><td>$950</td><td class="table-warning">$200</td></tr>
                    <tr><td>Walk-Behind Brush Cutter</td><td>$95</td><td>$150</td><td>$450</td><td>$1,000</td><td class="table-warning">$250</td></tr>
                    <tr><td>Gas-Powered Post Hole Digger</td><td>$50</td><td>$80</td><td>$250</td><td>$600</td><td class="table-warning">$150</td></tr>
                </tbody>
            </table>
            </div>

            <h6 class="mt-4">🧰 Power Tools & Small Equipment</h6>
            <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr><th>Item</th><th>Daily</th><th>Weekend</th><th>Weekly</th><th>Monthly</th><th class="table-warning">Deposit</th></tr>
                </thead>
                <tbody>
                    <tr><td>Generator (5,000+ Watts)</td><td>$60</td><td>$100</td><td>$250</td><td>$600</td><td class="table-warning">$150</td></tr>
                    <tr><td>Pressure Washer (Gas)</td><td>$50</td><td>$80</td><td>$200</td><td>$450</td><td class="table-warning">$100</td></tr>
                    <tr><td>Concrete Mixer</td><td>$65</td><td>$100</td><td>$300</td><td>$700</td><td class="table-warning">$150</td></tr>
                    <tr><td>Chainsaw (Gas)</td><td>$45</td><td>$70</td><td>$175</td><td>$400</td><td class="table-warning">$75</td></tr>
                    <tr><td>Tile Saw</td><td>$40</td><td>$65</td><td>$150</td><td>$350</td><td class="table-warning">$75</td></tr>
                </tbody>
            </table>
            </div>

            <h6 class="mt-4">🚚 Trailers & Hauling</h6>
            <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr><th>Item</th><th>Daily</th><th>Weekend</th><th>Weekly</th><th>Monthly</th><th class="table-warning">Deposit</th></tr>
                </thead>
                <tbody>
                    <tr><td>16’ Utility Trailer</td><td>$55</td><td>$90</td><td>$300</td><td>$750</td><td class="table-warning">$150</td></tr>
                    <tr><td>Enclosed Cargo Trailer</td><td>$75</td><td>$120</td><td>$375</td><td>$900</td><td class="table-warning">$200</td></tr>
                    <tr><td>Dump Trailer (7x14)</td><td>$125</td><td>$200</td><td>$700</td><td>$1,600</td><td class="table-warning">$300</td></tr>
                    <tr><td>Car Hauler</td><td>$85</td><td>$130</td><td>$400</td><td>$950</td><td class="table-warning">$200</td></tr>
                </tbody>
            </table>
            </div>

            <h6 class="mt-4">🚜 Farm & Ranch Equipment</h6>
            <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="thead-light">
                    <tr><th>Item</th><th>Daily</th><th>Weekend</th><th>Weekly</th><th>Monthly</th><th class="table-warning">Deposit</th></tr>
                </thead>
                <tbody>
                    <tr><td>Post Driver (Tow-Behind)</td><td>$90</td><td>$140</td><td>$400</td><td>$900</td><td class="table-warning">$300</td></tr>
                    <tr><td>PTO-Driven Auger</td><td>$75</td><td>$120</td><td>$350</td><td>$800</td><td class="table-warning">$250</td></tr>
                    <tr><td>Hay Spear or Forklift Attach.</td><td>$35</td><td>$50</td><td>$125</td><td>$300</td><td class="table-warning">$100</td></tr>
                    <tr><td>Small Utility Tractor</td><td>$175</td><td>$300</td><td>$850</td><td>$2,200</td><td class="table-warning">$400</td></tr>
                </tbody>
            </table>
            </div>

            <p class="text-muted mt-2"><em>All deposits are refundable upon safe return, pending inspection for damage, cleanliness, and fuel levels where applicable.</em></p>

        </div>
    </div>
</div>
';
            
                break;
            
        
            case 'promotions':
                $conn = connectDB();
        
                $content .= "<h2 class='mt-4'>Promotions Dashboard</h2>";
                $content .= "<a href='?page=add_promotion' class='btn btn-primary mb-4'>Add New Promotion</a>";
        
                $sql = "SELECT p.*, i.description AS item_description
                        FROM promotions p
                        LEFT JOIN items i ON p.item_id = i.id
                        ORDER BY p.promotion_date DESC";
                $result = $conn->query($sql);
        
                if (!$result) {
                    die("<div class='alert alert-danger'>Query failed: " . $conn->error . "</div>");
                }
        
                if ($result->num_rows > 0) {
                    $content .= "<div class='table-responsive'>";
                    $content .= "<table class='table table-striped'>";
                    $content .= "<thead><tr>
                                    <th>Date Promoted</th>
                                    <th>Item</th>
                                    <th>Platform</th>
                                    <th>Promotion Type</th>
                                    <th>Billing Method</th>
                                    <th>Cost</th>
                                    <th>Status</th>
                                 </tr></thead><tbody>";
        
                    while ($row = $result->fetch_assoc()) {
                        $promotion_date = strtotime($row['promotion_date']);
                        $today = strtotime(date('Y-m-d'));
                        $days_since = floor(($today - $promotion_date) / (60 * 60 * 24));
        
                        $status = ($days_since >= 14)
                            ? "<span class='badge bg-warning text-dark'>&#9888; Needs Reposting</span>"
                            : "<span class='badge bg-outline-success'>? Fresh</span>";
        
                        $content .= "<tr>";
                        $content .= "<td>" . date('Y-m-d', $promotion_date) . "</td>";
                        $content .= "<td>" . htmlspecialchars($row['item_description']) . "</td>";
                        $content .= "<td>" . htmlspecialchars($row['platform']) . "</td>";
                        $content .= "<td>" . htmlspecialchars($row['promotion_type']) . "</td>";
                        $content .= "<td>" . htmlspecialchars($row['billing_method']) . "</td>";
                        $content .= "<td>$" . number_format($row['cost'], 2) . "</td>";
                        $content .= "<td>" . $status . "</td>";
                        $content .= "</tr>";
                    }
        
                    $content .= "</tbody></table></div>";
                } else {
                    $content .= "<p>No promotions found yet.</p>";
                }
        
                $conn->close();
                break;
        
case 'generate_invoice':
    if (isset($_GET['sale_id'])) {
        $sale_id = (int)$_GET['sale_id'];
        
        $conn = connectDB();

        $sql = "SELECT s.*, i.*, c.name as consignor_name
                FROM sales s
                JOIN items i ON s.item_id = i.id
                LEFT JOIN consignors c ON i.consignor_id = c.id
                WHERE s.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();

            // Safely extract numeric values
            $sale_price      = floatval($data['sale_price']);
            $sales_tax       = floatval($data['sales_tax']);
            $delivery_fee    = floatval($data['delivery_fee']);
            $credit_applied  = floatval($data['credit_applied']);
            $total_amount    = $sale_price + $sales_tax + $delivery_fee;
            $final_due       = $total_amount - $credit_applied;

            // Start outputting directly
            echo "<!DOCTYPE html>
            <html>
            <head>
                <title>Sales Receipt - BACK2WORK EQUIPMENT</title>
                <style>
                    body { font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; font-size: 14px; line-height: 1.4; }
                    .invoice-box { padding: 30px; border: 1px solid #eee; box-shadow: 0 0 10px rgba(0, 0, 0, 0.15); }
                    h1, h2 { text-align: center; margin-bottom: 10px; }
                    .logo { text-align: center; margin-bottom: 20px; }
                    hr { margin: 20px 0; border: none; border-top: 1px solid #ddd; }
                    .section { margin-bottom: 25px; }
                    .section-title { background-color: #f5f5f5; padding: 8px; margin-bottom: 15px; font-weight: bold; border-left: 4px solid #666; }
                    table.details { width: 100%; border-collapse: collapse; }
                    table.details td { padding: 5px 10px; vertical-align: top; }
                    table.details td:first-child { width: 30%; font-weight: bold; }
                    .signature-line { margin-top: 40px; border-top: 1px solid #000; width: 45%; display: inline-block; }
                    .signature-space { display: inline-block; width: 10%; }
                    .trade-agreement { border: 1px solid #ddd; padding: 15px; margin-top: 30px; background-color: #f9f9f9; }
                    .trade-title { font-weight: bold; font-size: 16px; margin-bottom: 10px; text-align: center; }
                    .trade-content { font-size: 13px; }
                    .title-box { border: 1px solid #333; padding: 15px; margin-top: 30px; background-color: #f9f9f9; }
                    .notice { font-style: italic; margin-top: 15px; font-size: 12px; }
                    .checkbox { margin-right: 5px; border: 1px solid #000; padding: 1px 3px; display: inline-block; width: 12px; height: 12px; text-align: center; line-height: 12px; }
                    .checkbox-filled:after { content: '✓'; }
                    .page-break { page-break-before: always; }
                    .condition-field { border: 1px solid #ddd; padding: 10px; min-height: 50px; margin: 10px 0; }
                    .title-field { border: 1px solid #ddd; padding: 10px; min-height: 30px; margin: 10px 0; }
                    .print-btn { background: #2196F3; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin: 10px; }
                    .back-btn { background: #4CAF50; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; margin: 10px; }
                    .controls { text-align: center; margin-top: 30px; }
                    .title-status-box { padding: 15px; margin-top: 20px; border: 1px dashed #aaa; background-color: #f8f8f8; }
                    @media print {
                        .no-print { display: none; }
                        body { margin: 0; }
                        .invoice-box { border: none; box-shadow: none; }
                        .page-break { page-break-before: always; }
                    }
                </style>
            </head>
            <body>";

            echo "<div class='invoice-box'>";
            echo "<div class='logo'><h1>BACK2WORK EQUIPMENT</h1></div>";
            echo "<h2>Sales Receipt</h2><hr>";
            
            // Title status indicator at the top for immediate visibility
            $is_titled = !empty($data['is_titled']) && $data['is_titled'] == 1;
            echo "<div class='title-status-box'>";
            echo "<p style='font-size: 16px; margin: 0;'>";
            echo "<span class='checkbox " . ($is_titled ? "checkbox-filled" : "") . "'></span> ";
            echo "<strong>This equipment has a title that requires transfer.</strong>";
            echo "</p>";
            if ($is_titled) {
                echo "<p style='margin: 10px 0 0;'>Both seller and buyer are responsible for proper title handling and transfer according to state regulations.</p>";
            }
            echo "</div><hr>";
            
            echo "<div class='section'>";
            echo "<table class='details'>";
            echo "<tr><td>Receipt #:</td><td>" . str_pad($data['id'], 5, '0', STR_PAD_LEFT) . "</td></tr>";
            echo "<tr><td>Date:</td><td>" . date('m/d/Y', strtotime($data['sale_date'])) . "</td></tr>";
            echo "<tr><td>Sold To:</td><td>" . htmlspecialchars($data['buyer_name']) . "</td></tr>";
            
            if (!empty($data['buyer_phone'])) {
                echo "<tr><td>Phone:</td><td>" . htmlspecialchars($data['buyer_phone']) . "</td></tr>";
            }
            if (!empty($data['buyer_address'])) {
                echo "<tr><td>Address:</td><td>" . nl2br(htmlspecialchars($data['buyer_address'])) . "</td></tr>";
            }
            echo "</table>";
            echo "</div>";
            
            echo "<div class='section'>";
            echo "<div class='section-title'>Item Details</div>";
            echo "<table class='details'>";
            echo "<tr><td>Description:</td><td>" . htmlspecialchars($data['description']) . " (" . htmlspecialchars($data['make_model']) . ")</td></tr>";
            if (!empty($data['serial_number'])) {
                echo "<tr><td>Serial Number:</td><td>" . htmlspecialchars($data['serial_number']) . "</td></tr>";
            }
            if (!empty($data['vin'])) {
                echo "<tr><td>VIN:</td><td>" . htmlspecialchars($data['vin']) . "</td></tr>";
            }
            echo "<tr><td>Consignor:</td><td>" . htmlspecialchars($data['consignor_name']) . "</td></tr>";
            echo "</table>";
            echo "</div>";

            // Delivery details (if applicable)
            echo "<div class='section'>";
            echo "<div class='section-title'>Delivery Information</div>";
            echo "<table class='details'>";
            echo "<tr><td>Delivery Method:</td><td>" . (!empty($data['delivery_method']) ? ucfirst(htmlspecialchars($data['delivery_method'])) : 'None') . "</td></tr>";
            echo "<tr><td>Mileage (one-way):</td><td>" . floatval($data['mileage']) . " miles</td></tr>";
            
            if (!empty($data['scheduled_time']) && $data['scheduled_time'] !== '0000-00-00 00:00:00') {
                $scheduled = date('m/d/Y g:i A', strtotime($data['scheduled_time']));
                echo "<tr><td>Scheduled Time:</td><td>{$scheduled}</td></tr>";
            } else {
                echo "<tr><td>Scheduled Time:</td><td>N/A</td></tr>";
            }
            echo "</table>";
            echo "</div>";

            // Financial summary
            echo "<div class='section'>";
            echo "<div class='section-title'>Payment Details</div>";
            echo "<table class='details'>";
            echo "<tr><td>Sale Price:</td><td>$" . number_format($sale_price, 2) . "</td></tr>";
            if ($delivery_fee > 0) {
                echo "<tr><td>Delivery Fee:</td><td>$" . number_format($delivery_fee, 2) . "</td></tr>";
            }
            echo "<tr><td>Sales Tax (8.25%):</td><td>$" . number_format($sales_tax, 2) . "</td></tr>";
            echo "<tr><td>Total Amount:</td><td>$" . number_format($total_amount, 2) . "</td></tr>";

            if ($credit_applied > 0) {
                echo "<tr><td>Store Credit Applied:</td><td>-$" . number_format($credit_applied, 2) . "</td></tr>";
                echo "<tr><td>Final Amount Due:</td><td>$" . number_format($final_due, 2) . "</td></tr>";
            }
            
            echo "<tr><td>Payment Method:</td><td>" . htmlspecialchars($data['payment_method']) . "</td></tr>";
            echo "</table>";
            echo "</div>";

            // Add Known Conditions section with dedicated entry area
            echo "<div class='section'>";
            echo "<div class='section-title'>Equipment Condition & Disclosures</div>";
            
            echo "<table class='details'>";
            
            if (!empty($data['hours_used'])) {
                echo "<tr><td>Hours Used:</td><td>{$data['hours_used']} hours</td></tr>";
            }
            
            echo "</table>";
            
            echo "<div style='margin-top:15px;'>";
            echo "<p><strong>Known Conditions/Issues:</strong></p>";
            echo "<div class='condition-field'>";
            if (!empty($data['known_issues']) || !empty($data['condition_desc'])) {
                echo nl2br(htmlspecialchars($data['known_issues'] . "\n" . $data['condition_desc']));
            } else {
                echo "None provided";
            }
            echo "</div>";
            
            echo "<p><strong>Signs of Wear/Maintenance History:</strong></p>";
            echo "<div class='condition-field'>";
            if (!empty($data['wear_description']) || !empty($data['maintenance_history'])) {
                echo nl2br(htmlspecialchars($data['wear_description'] . "\n" . $data['maintenance_history']));
            } else {
                echo "None provided";
            }
            echo "</div>";
            
            echo "</div>";
            
            echo "<p class='notice'>Buyer acknowledges that they have received and reviewed all disclosures about this equipment's condition and accepts the equipment in its current condition.</p>";
            
            echo "</div>"; // End disclosures div
            
            // Title Information Section (improved with dedicated fields)
            if ($is_titled) {
                echo "<div class='section title-box'>";
                echo "<div class='section-title'>Title Information</div>";
                
                echo "<table class='details'>";
                
                if (!empty($data['title_number'])) {
                    echo "<tr><td>Title Number:</td><td>" . htmlspecialchars($data['title_number']) . "</td></tr>";
                } else {
                    echo "<tr><td>Title Number:</td><td class='title-field'></td></tr>";
                }
                
                if (!empty($data['title_state'])) {
                    $states = [
                        'AL'=>'Alabama', 'AK'=>'Alaska', 'AZ'=>'Arizona', 'AR'=>'Arkansas', 'CA'=>'California',
                        'CO'=>'Colorado', 'CT'=>'Connecticut', 'DE'=>'Delaware', 'FL'=>'Florida', 'GA'=>'Georgia',
                        'HI'=>'Hawaii', 'ID'=>'Idaho', 'IL'=>'Illinois', 'IN'=>'Indiana', 'IA'=>'Iowa',
                        'KS'=>'Kansas', 'KY'=>'Kentucky', 'LA'=>'Louisiana', 'ME'=>'Maine', 'MD'=>'Maryland',
                        'MA'=>'Massachusetts', 'MI'=>'Michigan', 'MN'=>'Minnesota', 'MS'=>'Mississippi', 'MO'=>'Missouri',
                        'MT'=>'Montana', 'NE'=>'Nebraska', 'NV'=>'Nevada', 'NH'=>'New Hampshire', 'NJ'=>'New Jersey',
                        'NM'=>'New Mexico', 'NY'=>'New York', 'NC'=>'North Carolina', 'ND'=>'North Dakota', 'OH'=>'Ohio',
                        'OK'=>'Oklahoma', 'OR'=>'Oregon', 'PA'=>'Pennsylvania', 'RI'=>'Rhode Island', 'SC'=>'South Carolina',
                        'SD'=>'South Dakota', 'TN'=>'Tennessee', 'TX'=>'Texas', 'UT'=>'Utah', 'VT'=>'Vermont',
                        'VA'=>'Virginia', 'WA'=>'Washington', 'WV'=>'West Virginia', 'WI'=>'Wisconsin', 'WY'=>'Wyoming',
                        'DC'=>'District of Columbia'
                    ];
                    $state_name = isset($states[$data['title_state']]) ? $states[$data['title_state']] : $data['title_state'];
                    echo "<tr><td>Title State:</td><td>" . htmlspecialchars($state_name) . "</td></tr>";
                } else {
                    echo "<tr><td>Title State:</td><td class='title-field'></td></tr>";
                }
                
                if (!empty($data['title_status'])) {
                    $status_labels = [
                        'clear' => 'Clear Title',
                        'lien' => 'Has Lien',
                        'salvage' => 'Salvage Title',
                        'rebuilt' => 'Rebuilt/Reconstructed',
                        'bonded' => 'Bonded Title',
                        'pending' => 'Pending/In Process'
                    ];
                    $status_display = isset($status_labels[$data['title_status']]) ? $status_labels[$data['title_status']] : htmlspecialchars($data['title_status']);
                    echo "<tr><td>Title Status:</td><td>" . $status_display . "</td></tr>";
                } else {
                    echo "<tr><td>Title Status:</td><td class='title-field'></td></tr>";
                }
                
                echo "</table>";
                
                // Title handling section with checkboxes
                echo "<div style='margin-top:20px;'>";
                echo "<p><strong>Title Handling:</strong></p>";
                echo "<p><span class='checkbox'></span> Title is present and transferred to buyer at time of sale</p>";
                echo "<p><span class='checkbox'></span> Title is not present - will be mailed to buyer</p>";
                echo "<p><span class='checkbox'></span> Title processing by BACK2WORK EQUIPMENT on behalf of buyer</p>";
                echo "</div>";
                
                echo "<p><strong>Additional Title Notes:</strong></p>";
                echo "<div class='title-field'></div>";
                
                echo "<p class='notice'>Both SELLER and BUYER share responsibility for proper title handling and transfer. Buyer acknowledges receipt of title documents as indicated above and assumes responsibility for timely title transfer according to state law.</p>";
                
                echo "</div>"; // End title information section
            }

            // Add new Trade Agreement section
            echo "<div class='section trade-agreement'>";
            echo "<div class='trade-title'>EQUIPMENT PURCHASE AGREEMENT</div>";
            echo "<div class='trade-content'>";
            echo "<p>This Equipment Purchase Agreement (\"Agreement\") is made between BACK2WORK EQUIPMENT (\"Seller\") and " . htmlspecialchars($data['buyer_name']) . " (\"Buyer\") on " . date('m/d/Y', strtotime($data['sale_date'])) . ".</p>";
            
            echo "<ol style='margin-top:10px; padding-left:20px;'>";
            echo "<li><strong>Equipment Sale.</strong> Buyer agrees to purchase the equipment described above in its present \"AS IS\" condition.</li>";
            
            echo "<li><strong>Disclaimers.</strong> Seller makes NO warranties, express or implied, regarding the equipment's condition, merchantability, or fitness for any particular purpose. Any manufacturer's warranty that may still be in effect may be transferable to Buyer.</li>";
            
            echo "<li><strong>Inspection.</strong> Buyer acknowledges having had the opportunity to inspect the equipment and accepts its condition with all faults and defects, whether apparent or not.</li>";
            
            echo "<li><strong>Final Sale.</strong> All sales are final. No returns, exchanges, or refunds will be provided except as specifically agreed to in writing by Seller.</li>";

            if ($is_titled) {
                echo "<li><strong>Title Transfer.</strong> Both Seller and Buyer share responsibility for proper title handling. Buyer agrees to complete all necessary paperwork to transfer the title within the timeframe required by state law. Seller agrees to provide necessary documentation for title transfer as indicated above.</li>";
            }
            
            echo "<li><strong>Risk of Loss.</strong> Risk of loss passes to Buyer upon completion of purchase and removal of equipment from Seller's premises, or upon delivery if Seller provides delivery service.</li>";
            
            echo "<li><strong>Indemnification.</strong> Buyer agrees to indemnify and hold Seller harmless from any claims, liabilities, costs, or expenses arising from Buyer's use of the equipment after purchase.</li>";
            echo "</ol>";
            
            echo "<p style='margin-top:15px;'><strong>By signing below, Buyer acknowledges reading, understanding, and agreeing to the terms of this Purchase Agreement.</strong></p>";
            echo "</div>"; // End trade-content
            echo "</div>"; // End trade-agreement

            // Signature line
            echo "<div style='margin-top:30px;'>";
            echo "<div class='signature-line'>Buyer Signature</div>";
            echo "<div class='signature-space'>&nbsp;</div>";
            echo "<div class='signature-line'>Date</div>";
            echo "</div>";

            // Add title transfer acknowledgment for titled items
            if ($is_titled) {
                echo "<div class='page-break'></div>";
                echo "<div style='margin-top: 40px;'>";
                echo "<h3 style='text-align: center;'>TITLE TRANSFER ACKNOWLEDGMENT</h3>";
                
                echo "<div style='border: 1px solid #ddd; padding: 15px; margin: 20px 0; background-color: #f9f9f9;'>";
                echo "<p style='font-weight:bold;'>EQUIPMENT INFORMATION:</p>";
                echo "<table class='details'>";
                echo "<tr><td style='width:30%'>Equipment Description:</td><td>" . htmlspecialchars($data['description']) . " (" . htmlspecialchars($data['make_model']) . ")</td></tr>";
                if (!empty($data['vin'])) {
                    echo "<tr><td>VIN/Serial Number:</td><td>" . htmlspecialchars($data['vin']) . "</td></tr>";
                } elseif (!empty($data['serial_number'])) {
                    echo "<tr><td>VIN/Serial Number:</td><td>" . htmlspecialchars($data['serial_number']) . "</td></tr>";
                }
                if (!empty($data['title_number'])) {
                    echo "<tr><td>Title Number:</td><td>" . htmlspecialchars($data['title_number']) . "</td></tr>";
                }
                echo "</table>";
                echo "</div>";
                
                echo "<p style='margin-top: 20px;'>I, <u>&nbsp;&nbsp;&nbsp;" . htmlspecialchars($data['buyer_name']) . "&nbsp;&nbsp;&nbsp;</u>, acknowledge receipt of:</p>";
                echo "<p style='margin-left: 20px;'>";
                echo "<span class='checkbox'></span> The original title for the equipment described above";
                echo "</p>";
                
                echo "<p style='margin-left: 20px;'>";
                echo "<span class='checkbox'></span> A copy of the title with transfer paperwork to be completed at the DMV";
                echo "</p>";
                
                echo "<p style='margin-left: 20px;'>";
                echo "<span class='checkbox'></span> A bill of sale only (title to be provided at a later date)";
                echo "</p>";
                
                echo "<p style='margin-left: 20px;'>";
                echo "<span class='checkbox'></span> Other: <span style='text-decoration:underline;'>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>";
                echo "</p>";
                
                echo "<p style='margin-top: 20px;'><strong>SHARED RESPONSIBILITIES:</strong></p>";
                echo "<ul style='margin-left:20px;'>";
                echo "<li><strong>Seller agrees to:</strong> Provide all available title documentation, disclose any known issues with the title, and assist with the transfer process as required by state law.</li>";
                echo "<li><strong>Buyer agrees to:</strong> Complete the title transfer promptly according to state law, pay any taxes and fees associated with the transfer, and notify Seller of any issues with the title documentation.</li>";
                echo "</ul>";
                
                echo "<p style='margin-top: 20px;'>I understand that failure to transfer the title within the time required by law may result in late fees and penalties for which I am solely responsible.</p>";
                
                echo "<div style='margin-top: 60px;'>";
                echo "<div class='signature-line'>Buyer Signature</div>";
                echo "<div class='signature-space'>&nbsp;</div>";
                echo "<div class='signature-line'>Date</div>";
                echo "</div>";
                
                echo "<div style='margin-top: 40px;'>";
                echo "<div class='signature-line'>Seller Representative</div>";
                echo "<div class='signature-space'>&nbsp;</div>";
                echo "<div class='signature-line'>Date</div>";
                echo "</div>";
                
                echo "</div>";
            }

            echo "<div class='controls no-print'>";
            echo "<a href='?page=sales_history' class='back-btn'>Back to Sales History</a>";
            echo "<button onclick='window.print()' class='print-btn'>Print Invoice</button>";
            echo "</div></div>"; // End invoice-box and controls

            echo "</body></html>";

            $conn->close();
            exit; // Important - stop execution here
        }

        $conn->close();
        echo "<p class='text-danger'>Sale record not found.</p>";
        exit;
    } else {
        $content = "<div class='alert alert-danger'>No sale ID provided</div>";
        echo displayPage($content);
    }
    break;

case 'add_item':
            $content .= displayAddItemForm();
            break;
            
      

            
       case 'lead_tracker':
            $conn = connectDB();
            $content .= "<h3>Recent Leads</h3><table border='1'><tr><th>Buyer</th><th>Contact</th><th>Item</th><th>Status</th><th>Date</th></tr>";
            $result = $conn->query("SELECT * FROM leads ORDER BY created_at DESC");
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $content .= "<tr><td>{$row['buyer']}</td><td>{$row['contact']}</td><td>{$row['item']}</td><td>{$row['status']}</td><td>{$row['created_at']}</td></tr>";
                }
            } else {
                $content .= "<tr><td colspan='5'>No leads found</td></tr>";
            }
            $content .= "</table>";
            
            $content .= "<form method='post' action='?page=lead_tracker'>
                <label>Buyer: <input type='text' name='buyer'></label><br>
                <label>Contact Info: <input type='text' name='contact'></label><br>
                <label>Item of Interest: <input type='text' name='item'></label><br>
                <label>Status:
                    <select name='status'>
                        <option>Inquired</option>
                        <option>Offered</option>
                        <option>Sold</option>
                    </select>
                </label><br>
                <input type='submit' name='submit_lead' value='Save Lead'>
            </form><hr>";
        
            if (isset($_POST['submit_lead'])) {
                $stmt = $conn->prepare("INSERT INTO leads (buyer, contact, item, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("ssss",
                    $_POST['buyer'],
                    $_POST['contact'],
                    $_POST['item'],
                    $_POST['status']
                );
                $stmt->execute();
                $content .= "<p style='color:green;'>Lead saved.</p>";
            }
            $conn->close();
            break;
                
            
        
            case 'inventory':
// First: Handle record_sale as a sub-action
                if (isset($_GET['action']) && $_GET['action'] === 'record_sale' && isset($_GET['item_id'])) {
                    $item_id = intval($_GET['item_id']);
                    $conn = connectDB();
            
                    $sql = "SELECT i.*, c.name as consignor_name, c.id as consignor_id
        FROM items i
        LEFT JOIN consignors c ON i.consignor_id = c.id
        WHERE i.id = ? 
          AND (i.status = 'active' OR i.status = 'house inventory')";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $item_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
            
                    if ($result && $result->num_rows > 0) {
                        $item = $result->fetch_assoc();
            
                        $sql_multiple = "SELECT COUNT(*) as item_count FROM items WHERE consignor_id = ? AND status = 'active'";
                        $stmt_multiple = $conn->prepare($sql_multiple);
                        $stmt_multiple->bind_param("i", $item['consignor_id']);
                        $stmt_multiple->execute();
                        $result_multiple = $stmt_multiple->get_result();
                        $multiple_items = ($result_multiple->fetch_assoc()['item_count'] > 1);
            
                        $content .= "<h2 class='mt-4'>Record Sale</h2>";
                        $content .= "<div class='card mb-3'><div class='card-header'><strong>Item Details</strong></div><div class='card-body'>";
                        $content .= "<p><strong>Description:</strong> " . htmlspecialchars($item['description']) . "</p>";
                        $content .= "<p><strong>Make/Model:</strong> " . htmlspecialchars($item['make_model']) . "</p>";
                        $content .= "<p><strong>Consignor:</strong> " . htmlspecialchars($item['consignor_name']) . " (ID: {$item['consignor_id']})</p>";
                        $content .= "<p><strong>Asking Price:</strong> $" . number_format($item['asking_price'], 2) . "</p>";
                        $content .= "<p><strong>Minimum Price:</strong> $" . number_format($item['min_price'], 2) . "</p>";
                        $content .= "</div></div>";
            
                        $content .= "<form method='post' enctype='multipart/form-data' action='?action=process_sale&item_id={$item_id}'>";
           
                        // Check if customer_credits table exists
$has_credits_table = false;
$credits_result = $conn->query("SHOW TABLES LIKE 'customer_credits'");
if ($credits_result && $credits_result->num_rows > 0) {
    $has_credits_table = true;
}
// Build Buyer dropdown
$content .= "<div class='form-group'>";
$content .= "<label for='buyer_name_select'>Buyer:</label>";
$content .= "<select name='buyer_name_select' id='buyer_name_select' class='form-control'>";
$content .= "<option value=''>-- New Buyer --</option>";
$buyers_sql = "
SELECT name, 
       IFNULL((
            SELECT SUM(cc.amount) 
            FROM customer_credits cc 
            WHERE cc.customer_name = all_buyers.name
       ), 0) 
       - IFNULL((
            SELECT SUM(cr.amount) 
            FROM credits_redeemed cr 
            WHERE cr.customer_name = all_buyers.name
       ), 0) AS available_credit
FROM (
    SELECT name FROM consignors
    UNION
    SELECT DISTINCT buyer_name AS name FROM sales WHERE buyer_name IS NOT NULL AND buyer_name != ''
    UNION
    SELECT DISTINCT customer_name AS name FROM customer_credits
) all_buyers
ORDER BY name;
";
$buyers_result = $conn->query($buyers_sql);
if ($buyers_result && $buyers_result->num_rows > 0) {
    while ($buyer = $buyers_result->fetch_assoc()) {
        $label = htmlspecialchars($buyer['name']);
        $creditAmount = floatval($buyer['available_credit']);
        if ($creditAmount > 0) {
            $label .= " (Has Store Credit: $" . number_format($creditAmount, 2) . ")";
        }
        $content .= "<option value='" . htmlspecialchars($buyer['name']) . "' data-credit='{$creditAmount}'>{$label}</option>";
    }
} else {
    $content .= "<option disabled>No buyers found</option>";
}
$content .= "</select>";
$content .= "</div>";
// Credit Info Display
$content .= "<div id='credit-display' class='text-success font-weight-bold mt-2'></div>";
$content .= "<div id='credit-balance' class='text-info mb-2'></div>";
$content .= "</div>"; // Close form-group
$content .= "<div class='container'>";
            
$content .= "<div class='form-group'>
<label for='buyer_name'>Buyer Name (if new):</label>
<input type='text' name='buyer_name' id='buyer_name' class='form-control'>
</div>";

    // Removed the duplicate Schedule Date & Time field

$content .= "<div class='form-group'>
    <label for='buyer_phone'>Buyer Phone:</label>
    <input type='text' class='form-control' name='buyer_phone' id='buyer_phone' >
</div>";
$content .= "<div class='form-group'>
    <label for='buyer_address'>Buyer Address:</label>
    <textarea class='form-control' name='buyer_address' id='buyer_address' rows='2' ></textarea>
</div>";
$content .= "<div class='form-group'>
    <label for='delivery_method'>Delivery</label>
    <select class='form-control' name='delivery_method' id='delivery_method' onchange='updatePreview()'>
        <option value=''>None</option>
        <option value='delivery'>Delivery ($50 flat fee)</option>
    </select>
</div>";
$content .= "<div class='form-group'>
    <label for='mileage'>Mileage (one way):</label>
    <input type='number' step='0.1' class='form-control' name='mileage' id='mileage' onchange='updatePreview()'>
    <small class='form-text text-muted'>Must be within 30 miles for delivery eligibility</small>
</div>";
$content .= "<div class='form-group'>
    <label for='scheduled_time'>Scheduled Pickup/Delivery Time:</label>
    <input type='datetime-local' class='form-control' name='scheduled_time' id='scheduled_time'>
</div>";
$content .= "<div class='form-group'>
<label for='buyer_license'>Upload Buyer's Driver's License:</label>
<input type='file' name='buyer_license' id='buyer_license' class='form-control-file' accept='image/*,.pdf' >
<small class='form-text text-muted'>Valid, unexpired photo ID required for all sales. PDF or image only.</small>
</div>
<div class='form-check mt-2'>
<input type='checkbox' name='not_military_id' id='not_military_id' class='form-check-input' >
<label class='form-check-label' for='not_military_id'>
I confirm this is <strong>not</strong> a military ID or Common Access Card (CAC)
</label>
</div>
<small class='form-text text-muted'>Federal law prohibits storing or copying U.S. military IDs. Please provide a state-issued ID or driver's license instead.</small>";
            
                        // Sale Price
$content .= "<div class='form-group row'>
<label for='sale_price' class='col-sm-2 col-form-label'>Sale Price ($):</label>
<div class='col-sm-4'>
    <input type='number' name='sale_price' id='sale_price' step='0.01' class='form-control' value='" . htmlspecialchars($item['asking_price']) . "' required onchange='updatePreview()'>
</div>
</div>";
// Payment Method
$content .= "<div class='form-group row'>
<label for='payment_method' class='col-sm-2 col-form-label'>Payment Method:</label>
<div class='col-sm-4'>
    <select name='payment_method' id='payment_method' class='form-control' onchange='updatePreview()'>
        <option value='Cash'>Cash</option>
        <option value='Credit Card'>Credit Card</option>
        <option value='Check'>Check</option>
        <option value='Bank Transfer'>Bank Transfer</option>
        <option value='Store Credit'>Store Credit</option>
    </select>
</div>
</div>";
// Apply Store Credit
$content .= "<div class='form-group row' id='credit-apply-group' style='display:none;'>
<label for='credit_applied' class='col-sm-2 col-form-label'>Apply Store Credit:</label>
<div class='col-sm-4'>
    <input type='number' step='0.01' name='credit_applied' id='credit_applied' class='form-control' value='0' onchange='updatePreview()'>
</div>
</div>";
// Balance Method
$content .= "<div class='form-group row' id='balance-method-group' style='display:none;'>
<label for='balance_payment_method' class='col-sm-2 col-form-label'>Balance Payment Method:</label>
<div class='col-sm-4'>
    <select name='balance_payment_method' id='balance_payment_method' class='form-control'>
        <option value='Cash'>Cash</option>
        <option value='Credit Card'>Credit Card</option>
    </select>
</div>
</div>";
// Safer hidden fields (check if values exist)
$content .= "<input type='hidden' id='category' value='" . htmlspecialchars($item['category']) . "'>";
$content .= "<input type='hidden' id='repeat_seller' value='" . (isset($item['repeat_seller']) && $item['repeat_seller'] ? "1" : "0") . "'>";
$content .= "<input type='hidden' id='multiple_items' value='" . ($multiple_items ? "1" : "0") . "'>";
// Sale Preview Card
$content .= "<div class='card mb-3' id='preview-card'><div class='card-header'><strong>Sale Preview</strong></div><div class='card-body'><div id='preview-content'></div></div></div>";
// Agreement Checkbox
$content .= "<div class='form-check mt-3'>
    <input type='checkbox' name='agreement_on_file' id='agreement_on_file' class='form-check-input' required checked>
    <label for='agreement_on_file' class='form-check-label'>
        Agreement is on file and card authorized for abandonment if needed
    </label>
</div>";
// Submit Button
$content .= "<button type='submit' class='btn btn-success mt-3'>Complete Sale</button>";
// Close the form
$content .= "</form>";
// EOT Section begins
$content .= <<<EOT
<script>
function updatePreview() {
    let salePrice = parseFloat(document.getElementById('sale_price').value) || 0;
    let creditApplied = parseFloat(document.getElementById('credit_applied')?.value || 0);
    let paymentMethod = document.getElementById('payment_method').value;
    let deliveryMethod = document.getElementById('delivery_method').value;
    let mileage = parseFloat(document.getElementById('mileage').value) || 0;
    
    // Calculate commission
    let commissionRate = 0;
    if (salePrice <= 250) {
        commissionRate = 25;
    } else if (salePrice <= 1000) {
        commissionRate = 10;
    } else if (salePrice <= 5000) {
        commissionRate = 8;
    } else {
        commissionRate = 6;
    }
    let commissionAmount = salePrice * (commissionRate / 100);
    if ((salePrice - commissionAmount) < 10) commissionAmount = salePrice - 10;
    if (commissionAmount > 500) commissionAmount = 500;
    
    // Calculate delivery fee
    let deliveryFee = 0;
    if (deliveryMethod === 'delivery') {
        deliveryFee = 50.00;
    }
    
    // Calculate totals
    let salesTax = salePrice * 0.0825;
    let subtotal = salePrice + deliveryFee;
    let totalDue = subtotal + salesTax;
    let balanceDue = totalDue - creditApplied;
    let toConsignor = salePrice - commissionAmount;
    let deliveryEligible = (toConsignor >= 75 && mileage <= 30);
    
    function formatMoney(n) {
        return '$' + n.toFixed(2);
    }
    
    let html = '<p><strong>Sale Price:</strong> ' + formatMoney(salePrice) + '</p>';
    html += '<p><strong>Commission (' + commissionRate + '%):</strong> ' + formatMoney(commissionAmount) + '</p>';
    
    if (deliveryFee > 0) {
        html += '<p><strong>Delivery Fee:</strong> ' + formatMoney(deliveryFee) + '</p>';
    }
    
    html += '<p><strong>Sales Tax (8.25%):</strong> ' + formatMoney(salesTax) + '</p>';
    
    if (creditApplied > 0) {
        html += '<p><strong>Store Credit Applied:</strong> -' + formatMoney(creditApplied) + '</p>';
    }
    
    html += '<p><strong>Total Amount Due:</strong> ' + formatMoney(totalDue) + '</p>';
    
    if (balanceDue > 0) {
        html += '<p class="text-danger"><strong>Remaining Balance:</strong> ' + formatMoney(balanceDue) + '</p>';
    } else {
        html += '<p class="text-success"><strong>Paid in Full</strong></p>';
    }
    
    html += '<p><strong>Amount to Consignor:</strong> ' + formatMoney(toConsignor) + '</p>';
    
    // Add delivery eligibility message
    if (deliveryMethod === 'delivery' || deliveryMethod === 'pickup') {
        let eligibilityIcon = deliveryEligible ? '✅ Eligible' : '❌ Not eligible';
        html += '<p><strong>Delivery Eligibility:</strong> ' + eligibilityIcon + '</p>';
        if (!deliveryEligible) {
            let reason = '';
            if (toConsignor < 75) reason += 'Profit to consignor less than $75. ';
            if (mileage > 30) reason += 'Distance exceeds 30 miles.';
            html += '<p class="text-danger"><small>' + reason + '</small></p>';
        }
    }
    
    document.getElementById('preview-content').innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    updatePreview();
    // Update preview whenever inputs change
    document.getElementById('buyer_name_select').addEventListener('change', function() {
        var selectedOption = this.options[this.selectedIndex];
        var creditAmount = parseFloat(selectedOption.getAttribute('data-credit') || 0);
        var creditDisplay = document.getElementById('credit-display');
        var creditApplyGroup = document.getElementById('credit-apply-group');
        
        if (creditAmount > 0) {
            creditDisplay.innerText = 'Available Store Credit: $' + creditAmount.toFixed(2);
            creditApplyGroup.style.display = 'block';
            document.getElementById('credit_applied').max = creditAmount;
        } else {
            creditDisplay.innerText = '';
            creditApplyGroup.style.display = 'none';
        }
    });
});
</script>

EOT;
            
                        $conn->close();
                        break;
                    }
                }
            
                // Default: Show Inventory Table
$conn = connectDB();
$filter = $_GET['filter'] ?? 'all'; // default to show all
$where_clause = "(i.status = 'active' OR i.status = 'house inventory')";
if ($filter === 'house') {
    $where_clause = "i.owned_by_company = 1 AND i.status = 'active'";
}
elseif ($filter === 'consignor') {
    $where_clause = "i.status = 'active' AND (i.owned_by_company = 0 OR i.owned_by_company IS NULL)";
}
$sql = "SELECT i.*, i.owned_by_company AS item_owned_by_company, i.agreement_file, c.name as consignor_name, DATEDIFF(CURDATE(), i.date_received) as days_on_lot
        FROM items i 
        LEFT JOIN consignors c ON i.consignor_id = c.id 
        WHERE $where_clause
        ORDER BY days_on_lot DESC";

$result = $conn->query($sql);
if (!$result) {
    die("<div class='alert alert-danger'>Query Error: " . $conn->error . "</div>");
}

$content .= "<h2 class='mt-4 mb-1'>Current Inventory</h2>";
$content .= "<div class='h6 text-danger font-weight-bold'>ADD CONSIGNOR FIRST!</div>";
$content .= "<hr class='mt-2 mb-4'>";

$content .= "<div class='mb-3'>";
$content .= "<a href='?page=add_item'' class='btn btn-secondary'>Add Item</a> ";
$content .= "<a href='?page=inventory&filter=all' class='btn btn-primary'>All Inventory</a> ";
$content .= "<a href='?page=inventory&filter=consignor' class='btn btn-info'>Consignor Inventory</a> ";
$content .= "<a href='?page=inventory&filter=house' class='btn btn-warning'>House Inventory</a>";
$content .= "</div>";
$content .= "<p class='text-muted'><strong>Note:</strong> Items not picked up within 7 days of contact after 120-day consignment period are subject to abandonment and may incur fees.</p>";

// Updated table header to include Disclosures column
$content .= "<table class='table table-striped'>";
$content .= "<thead><tr><th>Item</th><th>Consignor</th><th>Category</th><th>Asking Price</th><th>Days on Lot</th><th>Disclosures</th><th>Agreement</th><th>Actions</th></tr></thead><tbody>";

while ($row = $result->fetch_assoc()) {
    // Highlight based on days on lot
    $days = (int) $row['days_on_lot'];
    if ($days >= 120) {
        $days_display = "<span class='bg-danger text-white px-2 py-1 rounded'> {$days} </span>";
    } elseif ($days >= 60) {
        $days_display = "<span class='text-danger font-weight-bold'>{$days}</span>";
    } elseif ($days >= 30) {
        $days_display = "<span class='text-warning font-weight-bold'>{$days}</span>";
    } else {
        $days_display = "<span class='text-success'>{$days}</span>";
    }

    $content .= "<tr>";
    // Add title icon if item is titled
    $title_icon = (!empty($row['is_titled']) && $row['is_titled'] == 1) ? 
        "<span class='badge badge-info' title='This item has a title document'>🚗 Titled</span> " : "";
    $content .= "<td>{$title_icon}{$row['description']}<br><small>{$row['make_model']}</small></td>";

    if (!empty($row['consignor_name'])) {
        $content .= "<td>{$row['consignor_name']}</td>";
    } else {
        $content .= "<td><span class='badge bg-warning text-dark'>🏠 In-House Inventory</span></td>";
    }

    $content .= "<td>{$row['category']}</td>";
    $content .= "<td>$" . number_format($row['asking_price'], 2) . "</td>";
    $content .= "<td>{$days_display}</td>";
    
    // Add disclosures column
    $has_disclosures = !empty($row['known_issues']) || !empty($row['wear_description']) || !empty($row['hours_used']) || !empty($row['maintenance_history']);
    if ($has_disclosures) {
        $content .= "<td><span class='badge bg-warning text-dark' title='This item has disclosures'>⚠️ Disclosures</span></td>";
    } else {
        $content .= "<td><span class='badge bg-light text-dark'>None</span></td>";
    }

    // Agreement column with generate link
    $content .= "<td><a href='?action=generate_agreement&item_id={$row['id']}' target='_blank' class='btn btn-outline-secondary btn-sm'>View</a></td>";

    // Actions
    $content .= "<td>
    <a href='?page=item_details&item_id={$row['id']}' class='btn btn-sm btn-info'>Details</a>
    <a href='?page=inventory&action=record_sale&item_id={$row['id']}' class='btn btn-sm btn-success'>Sell</a>
    <a href='?page=edit_item&item_id={$row['id']}' class='btn btn-sm btn-warning'>Edit</a>";

    if ($row['rental_authorized']) {
        $content .= " <a href='?page=create_rental&item_id={$row['id']}' class='btn btn-sm btn-success'>Start Rental</a>";
    }

    if ($row['is_trade_authorized']) {
        $content .= " <a href='?page=inventory&action=start_trade&item_id={$row['id']}' class='btn btn-sm btn-info'>Start Trade</a>";
    }

    $content .= " <a href='?action=delete_item&item_id={$row['id']}' class='btn btn-sm btn-danger' onclick=\"return confirm('Are you sure you want to delete this item?')\">Delete</a>";
    
    // 🚨Insert abandonment check HERE
    // ======= [ START BADGE DISPLAY CLEAN FIX ] =======
    if ($row['item_owned_by_company']) {
        if ($row['status'] === 'abandoned') {
            $content .= "<br><span class='badge bg-warning text-dark'>?? Abandoned</span>";
        }
    } elseif (!empty($row['abandonment_date'])) {
        $abandonment_date = strtotime($row['abandonment_date']);
        $today = strtotime(date('Y-m-d'));
        $days_overdue = floor(($today - $abandonment_date) / (60 * 60 * 24));
        if ($days_overdue >= 7) {
            $content .= "<br><span class='badge bg-warning text-dark'>?? Ready for Takeover</span>";
            $content .= " <a href='?action=take_ownership&item_id={$row['id']}' class='btn btn-danger mt-1'>Take Ownership</a>";
        }
    }
    // ======= [ END FINAL VERSION ] =======
    $content .= "</td></tr>";
}

$content .= "</tbody></table>";
$conn->close();

break;

// =======================[ PAGE: add_consignor ]=======================
case 'add_consignor':
    $content .= displayAddConsignorForm();
    break;

            
        case 'aging_inventory':
            // Display items needing attention
            $aging_items = checkInventoryAging();
            
            $content .= "<h2 class='mt-4'>Inventory Requiring Attention</h2>";
            
            // 30-day items (need promotion)
            $content .= "<h3 class='mt-5'>Items at 30 Days (Need Promotion)</h3>";
            if (count($aging_items['items_30days']) > 0) {
                $content .= "<table class='table table-warning'>";
                $content .= "<thead><tr><th>Item</th><th>Consignor</th><th>Days</th><th>Asking Price</th><th>Actions</th></tr></thead>";
                $content .= "<tbody>";
                
                foreach ($aging_items['items_30days'] as $item) {
                    $content .= "<tr>";
                    $content .= "<td>{$item['description']}<br><small>{$item['make_model']}</small></td>";
                    $content .= "<td>{$item['name']}<br><small>{$item['email']}</small></td>";
                    $content .= "<td>{$item['days_on_lot']}</td>";
                    $content .= "<td>$" . number_format($item['asking_price'], 2) . "</td>";
                    $content .= "<td>
                                <a href='?action=add_promotion&item_id={$item['id']}' class='btn btn-sm btn-warning'>Add Promotion</a>
                                <a href='?action=email_consignor&item_id={$item['id']}&type=30day' class='btn btn-sm btn-info'>Email Consignor</a>
                                </td>";
                    $content .= "</tr>";
                }
                
                $content .= "</tbody></table>";
            } else {
                $content .= "<p>No items at 30-day mark.</p>";
            }
            
            // 60-day items (need action)
            $content .= "<h3 class='mt-4'>Items at 60 Days (Action Required)</h3>";
            if (count($aging_items['items_60days']) > 0) {
                $content .= "<table class='table table-danger'>";
                $content .= "<thead><tr><th>Item</th><th>Consignor</th><th>Days</th><th>Asking Price</th><th>Actions</th></tr></thead>";
                $content .= "<tbody>";
                
                foreach ($aging_items['items_60days'] as $item) {
                    $content .= "<tr>";
                    $content .= "<td>{$item['description']}<br><small>{$item['make_model']}</small></td>";
                    $content .= "<td>{$item['name']}<br><small>{$item['email']}</small></td>";
                    $content .= "<td>{$item['days_on_lot']}</td>";
                    $content .= "<td>$" . number_format($item['asking_price'], 2) . "</td>";
                    $content .= "<td>
                                <a href='?action=reduce_price&item_id={$item['id']}' class='btn btn-sm btn-danger'>Reduce Price</a>
                                <a href='?action=email_consignor&item_id={$item['id']}&type=60day' class='btn btn-sm btn-info'>Email Consignor</a>
                                <a href='?action=mark_pickup&item_id={$item['id']}' class='btn btn-sm btn-secondary'>Mark for Pickup</a>
                                </td>";
                    $content .= "</tr>";
                }
                
                $content .= "</tbody></table>";
            } else {
                $content .= "<p>No items at 60-day mark.</p>";
            }
            // 120-day items (final action required)
$content .= "<h3 class='mt-4'>Items at 120 Days (Final Action Required)</h3>";
if (count($aging_items['items_120days']) > 0) {
    $content .= "<table class='table table-dark'>"; // darker table to make it stand out
    $content .= "<thead><tr><th>Item</th><th>Consignor</th><th>Days</th><th>Asking Price</th><th>Actions</th></tr></thead>";
    $content .= "<tbody>";
    
    foreach ($aging_items['items_120days'] as $item) {
        $content .= "<tr>";
        $content .= "<td>{$item['description']}<br><small>{$item['make_model']}</small></td>";
        $content .= "<td>{$item['name']}<br><small>{$item['email']}</small></td>";
        $content .= "<td>{$item['days_on_lot']}</td>";
        $content .= "<td>$" . number_format($item['asking_price'], 2) . "</td>";
        $content .= "<td>
                    <a href='?action=liquidate_item&item_id={$item['id']}' class='btn btn-sm btn-dark'>Liquidate</a>
                    <a href='?action=email_consignor&item_id={$item['id']}&type=120day' class='btn btn-sm btn-info'>Email Consignor</a>
                    <a href='?action=mark_pickup&item_id={$item['id']}' class='btn btn-sm btn-secondary'>Mark for Pickup</a>
                    </td>";
        $content .= "</tr>";
    }
    
    $content .= "</tbody></table>";
} else {
    $content .= "<p>No items at 120-day mark.</p>";
}
            break;
            
            case 'sales_history':
    $conn = connectDB();

    $sql = "SELECT s.*, i.description, i.make_model, i.owned_by_company AS item_owned_by_company, i.status, 
                   c.name as consignor_name, s.buyer_name
            FROM sales s
            JOIN items i ON s.item_id = i.id 
            LEFT JOIN consignors c ON i.consignor_id = c.id
            ORDER BY s.sale_date DESC, s.id DESC";

    $result = $conn->query($sql);

    $content .= "<h2 class='mt-4'>Sales History</h2>";
    $content .= "<a href='?page=sales_customers' class='btn btn-secondary'>View Customers</a> ";
    $content .= "<a href='?page=credits_summary' class='btn btn-primary'>Credits</a> ";
    $content .= "<a href='?page=refunds&msg=issued' class='btn btn-danger'>Refund History</a>";
    $content .= "<table class='table table-striped mt-3'>";
    $content .= "<thead><tr>
        <th>Date</th>
        <th>Item</th>
        <th>Sale Price</th>
        <th>Delivery</th>
        <th>Commission</th>
        <th>Consignor</th>
        <th>Buyer</th>
        <th>Actions</th>
    </tr></thead>";
    $content .= "<tbody>";

    $total_sales = 0;
    $total_commission = 0;
    $total_delivery = 0;
    $is_first_row = true;

    while ($row = $result->fetch_assoc()) {
        $row_style = $is_first_row ? "style='font-weight: bold; background-color: #d1ecf1;'" : "";
        $is_first_row = false;
        $content .= "<tr {$row_style}>";

        // Sale Date
        $content .= "<td>" . date('m/d/Y', strtotime($row['sale_date'])) . "</td>";

        // Item Description
        $content .= "<td>" . htmlspecialchars($row['description']) . "<br><small>" . htmlspecialchars($row['make_model']) . "</small>";
        if (!empty($row['notes'])) {
            $content .= "<br><small class='text-info'>" . htmlspecialchars($row['notes']) . "</small>";
        }
        $content .= "</td>";

        // Sale Price
        $content .= "<td>$" . number_format($row['sale_price'], 2) . "</td>";

        // Delivery Fee
        $delivery_fee = isset($row['delivery_fee']) ? floatval($row['delivery_fee']) : 0;
        $mileage = isset($row['mileage']) ? floatval($row['mileage']) : 0;
        if ($delivery_fee > 0) {
            $eligibility = ($row['sale_price'] - $row['commission_amount'] >= 75 && $mileage <= 30);
            $icon = $eligibility ? "✅" : "❌";
            $content .= "<td>\${$delivery_fee}<br><small class='text-muted'>{$icon} " . ($eligibility ? "Eligible" : "Not Eligible") . "</small></td>";
        } else {
            $content .= "<td><span class='text-muted'>None</span></td>";
        }

        // Commission
        $content .= "<td>$" . number_format($row['commission_amount'], 2) . "</td>";

        // Consignor Info
        if ($row['item_owned_by_company']) {
            $consignor_display = ($row['status'] === 'abandoned')
                ? "<span class='badge badge-warning'>House Inventory (After Abandonment)</span>"
                : "<span class='badge badge-primary'>In-House Inventory</span>";
        } else {
            $consignor_display = "" . htmlspecialchars($row['consignor_name']);
        }
        $content .= "<td>{$consignor_display}</td>";

        // Buyer Name
        $content .= "<td>" . (!empty($row['buyer_name']) ? htmlspecialchars($row['buyer_name']) : htmlspecialchars($row['consignor_name'])) . "</td>";

        // Actions
        $content .= "<td>";
        $content .= "<a href='?page=sale_details&sale_id={$row['id']}' class='btn btn-sm btn-info'>Details</a> ";
        $content .= "<a href='?page=edit_sale&sale_id={$row['id']}' class='btn btn-sm btn-warning'>Edit</a> ";
        $content .= "<a href='?page=generate_invoice&sale_id={$row['id']}' target='_blank' class='btn btn-sm btn-primary'>Invoice</a> ";
        $content .= "<a href='?action=issue_refund&type=sale&id={$row['id']}' class='btn btn-sm btn-info'>Refund</a> ";

        if (!empty($row['license_file'])) {
            $content .= "<a href='" . htmlspecialchars($row['license_file']) . "' target='_blank' class='btn btn-sm btn-outline-secondary'>License</a> ";
        }

        // Pay Consignor
        if (isset($row['item_owned_by_company']) && intval($row['item_owned_by_company']) !== 1) {
            $button_class = $row['consignor_paid'] ? "success" : "warning";
            $button_text = $row['consignor_paid'] ? "Paid" : "Pay Consignor";
            $content .= "<a href='?action=mark_paid&sale_id={$row['id']}' class='btn btn-sm btn-{$button_class}'>{$button_text}</a> ";
        }

        $content .= "<a href='?action=delete_sale&sale_id={$row['id']}' class='btn btn-sm btn-danger' onclick=\"return confirm('Are you sure you want to delete this sale?')\">Delete</a>";
        $content .= "</td></tr>";

        $total_sales += $row['sale_price'];
        $total_commission += $row['commission_amount'];
        $total_delivery += $delivery_fee;
    }

    $content .= "</tbody><tfoot>";
    $content .= "<tr class='table-active'>";
    $content .= "<td colspan='2'><strong>Totals:</strong></td>";
    $content .= "<td><strong>$" . number_format($total_sales, 2) . "</strong></td>";
    $content .= "<td><strong>$" . number_format($total_delivery, 2) . "</strong></td>";
    $content .= "<td><strong>$" . number_format($total_commission, 2) . "</strong></td>";
    $content .= "<td colspan='3'></td>";
    $content .= "</tr>";
    $content .= "</tfoot></table>";

    $conn->close();
    break;

case 'sale_details':
    if (isset($_GET['sale_id'])) {
        $sale_id = (int) $_GET['sale_id'];
        $conn = connectDB();
        $stmt = $conn->prepare("SELECT s.*, i.id AS item_id, i.description, i.make_model, c.id AS consignor_id, c.name AS consignor_name
                                FROM sales s
                                LEFT JOIN items i ON s.item_id = i.id
                                LEFT JOIN consignors c ON i.consignor_id = c.id
                                WHERE s.id = ?");
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $sale = $result->fetch_assoc();

            $content .= "<h2 class='mt-4'>Sale Details</h2>";
            $content .= "<a href='?page=edit_sale&sale_id={$sale['id']}' class='btn btn-warning'>Edit</a>";

            $content .= "<p><strong>Date:</strong> " . htmlspecialchars($sale['sale_date']) . "</p>";

            $content .= "<div class='card mb-3'><div class='card-body'>";
            $content .= "<p><strong>Buyer:</strong> " . htmlspecialchars($sale['buyer_name']) . "</p>";

            $item_link = isset($sale['item_id']) ? "<a href='?page=item_details&item_id={$sale['item_id']}' class='btn btn-info btn-sm ml-2'>View Item</a>" : '';
            $content .= "<p><strong>Item:</strong> " . htmlspecialchars($sale['description']) . " (" . htmlspecialchars($sale['make_model']) . ") {$item_link}</p>";

            if (!empty($sale['consignor_id'])) {
                $content .= "<p><strong>Consignor:</strong> " . htmlspecialchars($sale['consignor_name']) .
                    " <a href='?page=consignor_details&consignor_id={$sale['consignor_id']}' class='btn btn-outline-secondary btn-sm ml-2'>View Consignor</a></p>";
            }

            $content .= "</div></div>";

            // Buyer contact
            $content .= "<p><strong>Phone:</strong> " . htmlspecialchars($sale['buyer_phone']) . "</p>";
            $content .= "<p><strong>Address:</strong> " . nl2br(htmlspecialchars($sale['buyer_address'])) . "</p>";

            // Delivery details - Make sure we properly check if delivery method exists and has a value
            $delivery_method = !empty($sale['delivery_method']) ? ucfirst($sale['delivery_method']) : 'None';
            $delivery_fee = floatval($sale['delivery_fee']);
            $mileage = floatval($sale['mileage']);
            $scheduled = !empty($sale['scheduled_time']) && $sale['scheduled_time'] != '0000-00-00 00:00:00' 
                       ? date('m/d/Y h:i A', strtotime($sale['scheduled_time'])) 
                       : 'N/A';

            $content .= "<hr>";
            $content .= "<p><strong>Delivery Method:</strong> {$delivery_method}</p>";
            $content .= "<p><strong>Mileage (one-way):</strong> {$mileage} miles</p>";
            $content .= "<p><strong>Delivery Fee:</strong> $" . number_format($delivery_fee, 2) . "</p>";
            $content .= "<p><strong>Scheduled:</strong> {$scheduled}</p>";

            // Financial summary
            $sale_price = floatval($sale['sale_price']);
            $commission = floatval($sale['commission_amount']);
            $sales_tax = $sale_price * 0.0825;
            $subtotal = $sale_price + $delivery_fee;
            $total_due = $subtotal + $sales_tax;
            $profit = $sale_price - $commission;

            $eligible = ($mileage <= 30 && $profit >= 75) ? "✅ Eligible" : "❌ Not Eligible";

            $content .= "<hr>";
            $content .= "<p><strong>Sale Price:</strong> $" . number_format($sale_price, 2) . "</p>";
            $content .= "<p><strong>Commission:</strong> $" . number_format($commission, 2) . "</p>";
            $content .= "<p><strong>Delivery Fee:</strong> $" . number_format($delivery_fee, 2) . "</p>";
            $content .= "<p><strong>Sales Tax (8.25%):</strong> $" . number_format($sales_tax, 2) . "</p>";
            $content .= "<p><strong>Total Amount Due:</strong> $" . number_format($total_due, 2) . "</p>";
            $content .= "<p><strong>Amount to Consignor:</strong> $" . number_format($profit, 2) . "</p>";
            $content .= "<p><strong>Delivery Eligibility:</strong> {$eligible}</p>";

        } else {
            $content .= "<div class='alert alert-danger'>Sale not found.</div>";
        }

        $stmt->close();
        $conn->close();
    }
    break;

    case 'edit_sale':
    if (isset($_GET['sale_id'])) {
        $sale_id = (int) $_GET['sale_id'];
        $conn = connectDB();

        $stmt = $conn->prepare("
            SELECT s.*, i.description, i.make_model
            FROM sales s
            LEFT JOIN items i ON s.item_id = i.id
            WHERE s.id = ?
        ");
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $sale = $result->fetch_assoc();

            $content .= "<h2 class='mt-4'>Edit Sale Details</h2>";
            $content .= "<form method='post' action='?action=save_sale_delivery&sale_id={$sale_id}' enctype='multipart/form-data'>";

            // Item summary
            $content .= "<div class='card mb-3'><div class='card-body'>";
            $content .= "<p><strong>Buyer:</strong> " . htmlspecialchars($sale['buyer_name']) . "</p>";
            $content .= "<p><strong>Item:</strong> " . htmlspecialchars($sale['description']) . " (" . htmlspecialchars($sale['make_model']) . ")</p>";
            $content .= "</div></div>";

            // Buyer contact
            $content .= "<div class='form-group'>
                <label for='buyer_phone'>Buyer Phone:</label>
                <input type='text' class='form-control' name='buyer_phone' id='buyer_phone' value='" . htmlspecialchars($sale['buyer_phone']) . "' >
            </div>";

            $content .= "<div class='form-group'>
                <label for='buyer_address'>Buyer Address:</label>
                <textarea class='form-control' name='buyer_address' id='buyer_address' rows='2' >" . htmlspecialchars($sale['buyer_address']) . "</textarea>
            </div>";

            $content .= "<div class='form-group'>
    <label for='delivery_method'>Delivery Method:</label>
    <select class='form-control' name='delivery_method' id='delivery_method' onchange='updatePreview()'>
        <option value=''>None</option>
        <option value='delivery'" . ($sale['delivery_method'] == 'delivery' ? " selected" : "") . ">Delivery ($50 flat fee)</option>
    </select>
</div>";


            $content .= "<div class='form-group'>
                <label for='delivery_fee'>Delivery Fee ($):</label>
                <input type='number' step='0.01' class='form-control' name='delivery_fee' id='delivery_fee' value='" . number_format($sale['delivery_fee'], 2) . "' onchange='updatePreview()'>
            </div>";

            $content .= "<div class='form-group'>
                <label for='mileage'>Mileage (one way):</label>
                <input type='number' step='0.1' class='form-control' name='mileage' id='mileage' value='" . floatval($sale['mileage']) . "' onchange='updatePreview()'>
                <small class='form-text text-muted'>Must be within 30 miles for delivery eligibility</small>
            </div>";

            $scheduled = !empty($sale['scheduled_time']) ? date('Y-m-d\TH:i', strtotime($sale['scheduled_time'])) : '';
            $content .= "<div class='form-group'>
                <label for='scheduled_time'>Scheduled Delivery Time:</label>
                <input type='datetime-local' class='form-control' name='scheduled_time' id='scheduled_time' value='{$scheduled}'>
            </div>";

            $content .= "<div class='form-group'>
                <label for='buyer_license'>Upload Buyer's Driver's License:</label>
                <input type='file' name='buyer_license' id='buyer_license' class='form-control-file' accept='image/*,.pdf'>
                <small class='form-text text-muted'>Valid, unexpired photo ID required. PDF or image only.</small>
            </div>";

            $content .= "<div class='form-check mt-2'>
                <input type='checkbox' name='not_military_id' id='not_military_id' class='form-check-input' >
                <label class='form-check-label' for='not_military_id'>
                    I confirm this is <strong>not</strong> a military ID or CAC
                </label>
                <small class='form-text text-muted'>We cannot store military IDs due to federal law.</small>
            </div>";

            // Sale price and commission
            $content .= "<div class='form-group row mt-3'>
                <label for='sale_price' class='col-sm-3 col-form-label'>Sale Price ($):</label>
                <div class='col-sm-4'>
                    <input type='number' step='0.01' class='form-control' name='sale_price' id='sale_price' value='" . number_format($sale['sale_price'], 2) . "' onchange='updatePreview()'>
                </div>
            </div>";

            $content .= "<div class='form-group row'>
                <label for='commission_amount' class='col-sm-3 col-form-label'>Commission ($):</label>
                <div class='col-sm-4'>
                    <input type='number' step='0.01' class='form-control' name='commission_amount' id='commission_amount' value='" . number_format($sale['commission_amount'], 2) . "' onchange='updatePreview()'>
                </div>
            </div>";

            // Preview card
            $content .= "<div class='card mb-3'><div class='card-header'><strong>Preview</strong></div><div class='card-body'><div id='preview-content'></div></div></div>";

            $content .= "<button type='submit' class='btn btn-success'>Save Changes</button>";
            $content .= "</form>";

            // JS preview script
            $content .= <<<EOT
<script>
function updatePreview() {
    let salePrice = parseFloat(document.getElementById('sale_price').value) || 0;
    let commission = parseFloat(document.getElementById('commission_amount').value) || 0;
    let deliveryFee = parseFloat(document.getElementById('delivery_fee').value) || 0;
    let mileage = parseFloat(document.getElementById('mileage').value) || 0;
    let deliveryMethod = document.getElementById('delivery_method').value;

    let salesTax = salePrice * 0.0825;
    let subtotal = salePrice + deliveryFee;
    let totalDue = subtotal + salesTax;
    let toConsignor = salePrice - commission;
    let eligible = (toConsignor >= 75 && mileage <= 30);

    function money(n) {
        return "$" + n.toFixed(2);
    }

    let html = "<p><strong>Sale Price:</strong> " + money(salePrice) + "</p>";
    html += "<p><strong>Commission:</strong> " + money(commission) + "</p>";
    html += "<p><strong>Delivery Fee:</strong> " + money(deliveryFee) + "</p>";
    html += "<p><strong>Sales Tax (8.25%):</strong> " + money(salesTax) + "</p>";
    html += "<p><strong>Total Due:</strong> " + money(totalDue) + "</p>";
    html += "<p><strong>Amount to Consignor:</strong> " + money(toConsignor) + "</p>";

    // In your updatePreview() function
if (deliveryMethod === 'delivery') {
    html += "<p><strong>Delivery Eligibility:</strong> " + (eligible ? "✅ Eligible" : "❌ Not Eligible") + "</p>";
    if (!eligible) {
        if (toConsignor < 75) html += "<p class='text-danger small'>Profit to consignor is under $75.</p>";
        if (mileage > 30) html += "<p class='text-danger small'>Mileage exceeds 30 miles.</p>";
    }
}


    document.getElementById('preview-content').innerHTML = html;
}
document.addEventListener('DOMContentLoaded', updatePreview);
</script>
EOT;
        } else {
            $content .= "<div class='alert alert-danger'>Sale not found.</div>";
        }

        $stmt->close();
        $conn->close();
    }
    break;

    case 'save_sale_delivery':
    if (isset($_GET['sale_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $sale_id = (int) $_GET['sale_id'];
        $conn = connectDB();

        // Sanitize inputs
        $buyer_phone     = $_POST['buyer_phone'] ?? '';
        $buyer_address   = $_POST['buyer_address'] ?? '';
        $delivery_method = $_POST['delivery_method'] ?? '';
        $delivery_fee    = floatval($_POST['delivery_fee'] ?? 0);
        $mileage         = floatval($_POST['mileage'] ?? 0);
        $scheduled_time  = !empty($_POST['scheduled_time']) ? $_POST['scheduled_time'] : null;
        $sale_price      = floatval($_POST['sale_price'] ?? 0);
        $commission_amt  = floatval($_POST['commission_amount'] ?? 0);

        // Optional: Recalculate tax
        $sales_tax = round($sale_price * 0.0825, 2);

        // Upload license if new one is provided
        $license_file = null;
        if (isset($_FILES['buyer_license']) && $_FILES['buyer_license']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . "/uploads/licenses/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $filename = time() . "_" . basename($_FILES["buyer_license"]["name"]);
            $relative_path = "uploads/licenses/" . $filename;
            $full_path = $upload_dir . $filename;

            if (move_uploaded_file($_FILES["buyer_license"]["tmp_name"], $full_path)) {
                $license_file = $relative_path;
            }
        }

        // Build dynamic update query
        $sql = "UPDATE sales SET
                    buyer_phone = ?,
                    buyer_address = ?,
                    delivery_method = ?,
                    delivery_fee = ?,
                    mileage = ?,
                    scheduled_time = ?,
                    sale_price = ?,
                    commission_amount = ?,
                    sales_tax = ?";

        if ($license_file !== null) {
            $sql .= ", license_file = ?";
        }

        $sql .= " WHERE id = ?";

        $stmt = $conn->prepare($sql);

        if ($license_file !== null) {
            $stmt->bind_param("sssdddddssi", 
                $buyer_phone,
                $buyer_address,
                $delivery_method,
                $delivery_fee,
                $mileage,
                $scheduled_time,
                $sale_price,
                $commission_amt,
                $sales_tax,
                $license_file,
                $sale_id
            );
        } else {
            $stmt->bind_param("sssddddddi", 
                $buyer_phone,
                $buyer_address,
                $delivery_method,
                $delivery_fee,
                $mileage,
                $scheduled_time,
                $sale_price,
                $commission_amt,
                $sales_tax,
                $sale_id
            );
        }

        $stmt->execute();
        $stmt->close();
        $conn->close();

        header("Location: ?page=sale_details&sale_id={$sale_id}&msg=updated");
        exit;
    }
    break;
    
            
case 'upload_license':
    $conn = connectDB();
    $sale_id = isset($_GET['sale_id']) ? (int) $_GET['sale_id'] : 0;
    if (
        $sale_id > 0 &&
        isset($_FILES['buyer_license']) &&
        $_FILES['buyer_license']['error'] === UPLOAD_ERR_OK
    ) {
        $upload_dir = __DIR__ . "/uploads/licenses/";
        // Make the directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        // Generate a unique filename
        $filename = time() . "_" . basename($_FILES['buyer_license']['name']);
        $relative_path = "uploads/licenses/" . $filename;
        $full_path = $upload_dir . $filename;
        // Move file and update DB
        if (move_uploaded_file($_FILES['buyer_license']['tmp_name'], $full_path)) {
            $stmt = $conn->prepare("UPDATE sales SET license_file = ? WHERE id = ?");
            $stmt->bind_param("si", $relative_path, $sale_id);
            $stmt->execute();
            $stmt->close();
            header("Location: ?page=sales_history&msg=license_uploaded");
            exit;
        } else {
            $content .= "<div class='alert alert-danger'>Failed to move uploaded file.</div>";
        }
    } else {
        $content .= "<div class='alert alert-danger'>Missing sale ID or no valid file uploaded.</div>";
    }
    $conn->close();
    break;

            case 'customers':
                $conn = connectDB();
                $result = $conn->query("SELECT * FROM customers ORDER BY created_at DESC");
            
                $content .= "<h2 class='mt-4'>Customers</h2>";
                if ($result->num_rows > 0) {
                    $content .= "<table class='table table-bordered table-sm'><thead><tr>
                        <th>Name</th><th>Phone</th><th>Email</th><th>Actions</th>
                    </tr></thead><tbody>";
                    
                    while ($row = $result->fetch_assoc()) {
                        $content .= "<tr>
                            <td>{$row['name']}</td>
                            <td>{$row['phone']}</td>
                            <td>{$row['email']}</td>
                            <td>
                                <a href='?action=delete_customer&id={$row['id']}' class='btn btn-sm btn-danger' onclick=\"return confirm('Delete this customer?')\">Delete</a>
                            </td>
                        </tr>";
                    }
            
                    $content .= "</tbody></table>";
                } else {
                    $content .= "<p class='text-muted'>No customers found.</p>";
                }
            
                $conn->close();
                break;
            
            
        case 'consignors':
            // Display consignors list
            $conn = connectDB();
            
            $sql = "SELECT c.*, 
                   (SELECT COUNT(*) FROM items WHERE consignor_id = c.id AND status = 'active') as active_items,
                   (SELECT COUNT(*) FROM items i JOIN sales s ON i.id = s.item_id WHERE i.consignor_id = c.id) as sold_items
                   FROM consignors c
                   ORDER BY c.name";
            
            $result = $conn->query($sql);
            
            $content .= "<h2 class='mt-4'>Consignors</h2>";
            $content .= "<a href='?page=add_consignor' class='btn btn-primary mb-3'>Add New Consignor</a>";
            $content .= "<table class='table table-striped'>";
            $content .= "<thead><tr><th>Name</th><th>Contact</th><th>Active Items</th><th>Sold Items</th><th>Repeat Seller</th><th>Actions</th></tr></thead>";
            $content .= "<tbody>";
            
            while ($row = $result->fetch_assoc()) {
                $content .= "<tr>";
                $content .= "<td>{$row['name']}</td>";
                $content .= "<td>{$row['email']}<br>{$row['phone']}</td>";
                $content .= "<td>{$row['active_items']}</td>";
                $content .= "<td>{$row['sold_items']}</td>";
                $content .= "<td>" . ($row['repeat_seller'] ? "Yes" : "No") . "</td>";
                $content .= "<td>
                    <a href='?page=consignor_details&consignor_id={$row['id']}' class='btn btn-sm btn-info'>Details</a>
                    <a href='?page=edit_consignor&consignor_id={$row['id']}' class='btn btn-sm btn-warning'>Edit</a>
                    <a href='?action=delete_consignor&consignor_id={$row['id']}' class='btn btn-sm btn-danger' onclick=\"return confirm('Are you sure you want to delete this consignor? This cannot be undone.')\">Delete</a>
                </td>";
                $content .= "</tr>";
            }
            
            
            $content .= "</tbody></table>";
            
            $conn->close();
            break;
        
            
case 'commission_rates':
    $content .= "<h2>Commission Rate Guidelines</h2>";
    $content .= "<div class='card'>";
    $content .= "<div class='card-body'>";
    $content .= "<h4>Trailer, Tractors & Mowers</h4>";
    $content .= "<ul><li><strong>Rate:</strong> 15%</li><li><strong>Minimum Commission:</strong> \$250</li></ul>";
    $content .= "<h4>Tools & Small Gear</h4>";
    $content .= "<ul>
        <li><strong>Flat Fee:</strong>
            <ul>
                <li>\$50 if sale price is under \$500</li>
                <li>\$150 if sale price is \$500 or more</li>
            </ul>
        </li>
    </ul>";
    $content .= "<h4>Standard Equipment</h4>";
    $content .= "<ul>
        <li>Sale price under \$1,000: 20% (Minimum \$100)</li>
        <li>Sale price between \$1,000 and \$4,999.99: 15%</li>
        <li>Sale price \$5,000 or more: 10%</li>
    </ul>";
    $content .= "<h4>Discounts (applies only to Standard Equipment)</h4>";
    $content .= "<ul>
        <li><strong>Repeat Seller:</strong> -2%</li>
        <li><strong>Multiple Active Items:</strong> -1%</li>
    </ul>";
    $content .= "<p class='text-muted'><em>These rates are automatically calculated in the sales system during record entry. Flat fees override percentage-based calculations where applicable.</em></p>";
    $content .= "</div></div>";
    break;

    case 'download_tax_report_csv':
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=tax_report_' . date('Y') . '.csv');

    $conn = connectDB();

    // Get all-time sales totals
    $sql = "SELECT 
                SUM(sale_price) AS total_sales, 
                SUM(commission_amount) AS total_commission,
                SUM(delivery_fee) AS total_delivery
            FROM sales";
    $result = $conn->query($sql);
    $tax_data = $result->fetch_assoc();

    // Get all-time rental totals
    $sql_rentals = "SELECT 
                SUM(total_amount) AS total_rentals, 
                SUM(deposit) AS total_deposits,
                SUM(deposit_returned) AS total_deposits_returned
            FROM rentals";
    $result_rentals = $conn->query($sql_rentals);
    $rental_tax_data = $result_rentals->fetch_assoc();

    // Get this year's sales totals
    $sql_year = "SELECT 
                    SUM(sale_price) AS total_sales_year, 
                    SUM(commission_amount) AS total_commission_year,
                    SUM(delivery_fee) AS total_delivery_year
                FROM sales 
                WHERE YEAR(sale_date) = YEAR(CURDATE())";
    $result_year = $conn->query($sql_year);
    $tax_data_year = $result_year->fetch_assoc();

    // Get this year's rental totals
    $sql_rentals_year = "SELECT 
                    SUM(total_amount) AS total_rentals_year,
                    SUM(deposit) AS total_deposits_year,
                    SUM(deposit_returned) AS total_deposits_returned_year
                FROM rentals 
                WHERE YEAR(rental_start) = YEAR(CURDATE())";
    $result_rentals_year = $conn->query($sql_rentals_year);
    $rental_tax_data_year = $result_rentals_year->fetch_assoc();

    // Calculate totals
    $all_time_total = floatval($tax_data['total_sales']) + floatval($rental_tax_data['total_rentals']);
    $year_total = floatval($tax_data_year['total_sales_year']) + floatval($rental_tax_data_year['total_rentals_year']);
    
    // Calculate retained deposits
    $all_time_retained_deposits = floatval($rental_tax_data['total_deposits']) - floatval($rental_tax_data['total_deposits_returned']);
    $year_retained_deposits = floatval($rental_tax_data_year['total_deposits_year']) - floatval($rental_tax_data_year['total_deposits_returned_year']);

    // Prepare quarters for this year
    $quarters = [
        'Q1' => ['start' => '01-01', 'end' => '03-31'],
        'Q2' => ['start' => '04-01', 'end' => '06-30'],
        'Q3' => ['start' => '07-01', 'end' => '09-30'],
        'Q4' => ['start' => '10-01', 'end' => '12-31']
    ];

    $quarter_data = [];
    $rental_quarter_data = [];

    foreach ($quarters as $quarter => $dates) {
        $start_date = date('Y') . '-' . $dates['start'];
        $end_date = date('Y') . '-' . $dates['end'];

        // Sales quarterly data
        $sql_q = "SELECT 
                    SUM(sale_price) AS total_sales, 
                    SUM(commission_amount) AS total_commission,
                    SUM(delivery_fee) AS total_delivery
                  FROM sales 
                  WHERE sale_date BETWEEN '$start_date' AND '$end_date'";
        $result_q = $conn->query($sql_q);
        $quarter_data[$quarter] = $result_q->fetch_assoc();

        // Rentals quarterly data
        $sql_rental_q = "SELECT 
                    SUM(total_amount) AS total_rentals,
                    SUM(deposit) AS total_deposits,
                    SUM(deposit_returned) AS total_deposits_returned
                  FROM rentals 
                  WHERE rental_start BETWEEN '$start_date' AND '$end_date'";
        $result_rental_q = $conn->query($sql_rental_q);
        $rental_quarter_data[$quarter] = $result_rental_q->fetch_assoc();
    }

    $conn->close();

    // Build CSV
    $output = fopen('php://output', 'w');

    // Add report header
    fputcsv($output, ['Back2Work Equipment - Tax Report Summary - ' . date('Y')]);
    fputcsv($output, ['Generated on', date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    // All-time totals
    fputcsv($output, ['ALL-TIME TOTALS']);
    fputcsv($output, ['Description', 'Amount']);
    fputcsv($output, ['ALL-TIME Total Income (Sales + Rentals)', number_format($all_time_total, 2)]);
    fputcsv($output, ['ALL-TIME Sales Revenue', number_format($tax_data['total_sales'], 2)]);
    fputcsv($output, ['ALL-TIME Rental Revenue', number_format($rental_tax_data['total_rentals'], 2)]);
    fputcsv($output, ['ALL-TIME Retained Deposits', number_format($all_time_retained_deposits, 2)]);
    fputcsv($output, ['ALL-TIME Delivery Fees', number_format($tax_data['total_delivery'], 2)]);
    fputcsv($output, ['ALL-TIME Commission Earned', number_format($tax_data['total_commission'], 2)]);
    fputcsv($output, []);

    // Current year totals
    fputcsv($output, ['CURRENT YEAR (' . date('Y') . ') TOTALS']);
    fputcsv($output, [date('Y') . ' Total Income (Sales + Rentals)', number_format($year_total, 2)]);
    fputcsv($output, [date('Y') . ' Sales Revenue', number_format($tax_data_year['total_sales_year'], 2)]);
    fputcsv($output, [date('Y') . ' Rental Revenue', number_format($rental_tax_data_year['total_rentals_year'], 2)]);
    fputcsv($output, [date('Y') . ' Retained Deposits', number_format($year_retained_deposits, 2)]);
    fputcsv($output, [date('Y') . ' Delivery Fees', number_format($tax_data_year['total_delivery_year'], 2)]);
    fputcsv($output, [date('Y') . ' Commission Earned', number_format($tax_data_year['total_commission_year'], 2)]);
    fputcsv($output, []);

    // Quarterly breakdown
    fputcsv($output, ['Quarterly Report - ' . date('Y')]);
    fputcsv($output, ['Quarter', 'Sales', 'Rentals', 'Total Income', 'Deposits Kept', 'Delivery Fees', 'Commission']);

    foreach ($quarter_data as $quarter => $data) {
        $rental_data = $rental_quarter_data[$quarter];
        $quarter_deposits_kept = floatval($rental_data['total_deposits']) - floatval($rental_data['total_deposits_returned']);
        $quarter_total_income = floatval($data['total_sales']) + floatval($rental_data['total_rentals']);
        
        fputcsv($output, [
            $quarter,
            number_format($data['total_sales'], 2),
            number_format($rental_data['total_rentals'], 2),
            number_format($quarter_total_income, 2),
            number_format($quarter_deposits_kept, 2),
            number_format($data['total_delivery'], 2),
            number_format($data['total_commission'], 2)
        ]);
    }

    fclose($output);
    exit;

    case 'tax_report':
    $conn = connectDB();

    // Get all-time sales totals
    $sql = "SELECT 
                SUM(sale_price) AS total_sales, 
                SUM(commission_amount) AS total_commission,
                SUM(delivery_fee) AS total_delivery
            FROM sales";
    $result = $conn->query($sql);
    $tax_data = $result->fetch_assoc();

    // Get all-time rental totals
    $sql_rentals = "SELECT 
                SUM(total_amount) AS total_rentals, 
                SUM(deposit) AS total_deposits,
                SUM(deposit_returned) AS total_deposits_returned
            FROM rentals";
    $result_rentals = $conn->query($sql_rentals);
    $rental_tax_data = $result_rentals->fetch_assoc();

    // Get this year's sales totals
    $sql_year = "SELECT 
                    SUM(sale_price) AS total_sales_year, 
                    SUM(commission_amount) AS total_commission_year,
                    SUM(delivery_fee) AS total_delivery_year
                 FROM sales 
                 WHERE YEAR(sale_date) = YEAR(CURDATE())";
    $result_year = $conn->query($sql_year);
    $tax_data_year = $result_year->fetch_assoc();

    // Get this year's rental totals
    $sql_rentals_year = "SELECT 
                    SUM(total_amount) AS total_rentals_year,
                    SUM(deposit) AS total_deposits_year,
                    SUM(deposit_returned) AS total_deposits_returned_year
                 FROM rentals 
                 WHERE YEAR(rental_start) = YEAR(CURDATE())";
    $result_rentals_year = $conn->query($sql_rentals_year);
    $rental_tax_data_year = $result_rentals_year->fetch_assoc();

    // Quarterly breakdown for sales
    $quarters = [
        'Q1' => ['start' => '01-01', 'end' => '03-31'],
        'Q2' => ['start' => '04-01', 'end' => '06-30'],
        'Q3' => ['start' => '07-01', 'end' => '09-30'],
        'Q4' => ['start' => '10-01', 'end' => '12-31']
    ];
    $quarter_data = [];
    $rental_quarter_data = [];

    foreach ($quarters as $quarter => $dates) {
        $start_date = date('Y') . '-' . $dates['start'];
        $end_date = date('Y') . '-' . $dates['end'];

        // Sales quarterly data
        $sql_q = "SELECT 
                    SUM(sale_price) AS total_sales, 
                    SUM(commission_amount) AS total_commission,
                    SUM(delivery_fee) AS total_delivery
                  FROM sales 
                  WHERE sale_date BETWEEN '$start_date' AND '$end_date'";
        $result_q = $conn->query($sql_q);
        $quarter_data[$quarter] = $result_q->fetch_assoc();

        // Rentals quarterly data
        $sql_rental_q = "SELECT 
                    SUM(total_amount) AS total_rentals,
                    SUM(deposit) AS total_deposits,
                    SUM(deposit_returned) AS total_deposits_returned
                  FROM rentals 
                  WHERE rental_start BETWEEN '$start_date' AND '$end_date'";
        $result_rental_q = $conn->query($sql_rental_q);
        $rental_quarter_data[$quarter] = $result_rental_q->fetch_assoc();
    }

    $conn->close();

    // Calculate totals including both sales and rentals
    $all_time_total = floatval($tax_data['total_sales']) + floatval($rental_tax_data['total_rentals']);
    $year_total = floatval($tax_data_year['total_sales_year']) + floatval($rental_tax_data_year['total_rentals_year']);

    // Retained deposits (deposits kept, not returned)
    $all_time_retained_deposits = floatval($rental_tax_data['total_deposits']) - floatval($rental_tax_data['total_deposits_returned']);
    $year_retained_deposits = floatval($rental_tax_data_year['total_deposits_year']) - floatval($rental_tax_data_year['total_deposits_returned_year']);

    // Build output
    $content .= "<h2 class='mt-5'>Tax Report Summary</h2>";
    $content .= "<a href='?page=download_tax_report_csv' class='btn btn-primary mb-3'>Download CSV</a>";

    // Combined summary table
    $content .= "<div class='table-responsive'>";
    $content .= "<table class='table table-bordered'>";
    $content .= "<thead><tr><th>Description</th><th>Amount</th></tr></thead><tbody>";

    $content .= "<tr class='table-primary'><td colspan='2'><strong>ALL-TIME TOTALS</strong></td></tr>";
    $content .= "<tr><td><strong>ALL-TIME Total Income (Sales + Rentals)</strong></td><td>$" . number_format($all_time_total, 2) . "</td></tr>";
    $content .= "<tr><td><strong>ALL-TIME Sales Revenue</strong></td><td>$" . number_format($tax_data['total_sales'], 2) . "</td></tr>";
    $content .= "<tr><td><strong>ALL-TIME Rental Revenue</strong></td><td>$" . number_format($rental_tax_data['total_rentals'], 2) . "</td></tr>";
    $content .= "<tr><td><strong>ALL-TIME Retained Deposits</strong></td><td>$" . number_format($all_time_retained_deposits, 2) . "</td></tr>";
    $content .= "<tr><td><strong>ALL-TIME Delivery Fees</strong></td><td>$" . number_format($tax_data['total_delivery'], 2) . "</td></tr>";
    $content .= "<tr><td><strong>ALL-TIME Commission Earned</strong></td><td>$" . number_format($tax_data['total_commission'], 2) . "</td></tr>";

    $content .= "<tr class='table-info'><td colspan='2'><strong>CURRENT YEAR (" . date('Y') . ") TOTALS</strong></td></tr>";
    $content .= "<tr><td><strong>" . date('Y') . " Total Income (Sales + Rentals)</strong></td><td>$" . number_format($year_total, 2) . "</td></tr>";
    $content .= "<tr><td><strong>" . date('Y') . " Sales Revenue</strong></td><td>$" . number_format($tax_data_year['total_sales_year'], 2) . "</td></tr>";
    $content .= "<tr><td><strong>" . date('Y') . " Rental Revenue</strong></td><td>$" . number_format($rental_tax_data_year['total_rentals_year'], 2) . "</td></tr>";
    $content .= "<tr><td><strong>" . date('Y') . " Retained Deposits</strong></td><td>$" . number_format($year_retained_deposits, 2) . "</td></tr>";
    $content .= "<tr><td><strong>" . date('Y') . " Delivery Fees</strong></td><td>$" . number_format($tax_data_year['total_delivery_year'], 2) . "</td></tr>";
    $content .= "<tr><td><strong>" . date('Y') . " Commission Earned</strong></td><td>$" . number_format($tax_data_year['total_commission_year'], 2) . "</td></tr>";

    $content .= "</tbody></table></div>";

    // Quarterly breakdown
    $content .= "<h2 class='mt-5'>Quarterly Sales Report (" . date('Y') . ")</h2>";
    $content .= "<div class='table-responsive'>";
    $content .= "<table class='table table-bordered'>";
    $content .= "<thead><tr><th>Quarter</th><th>Sales</th><th>Rentals</th><th>Total Income</th><th>Deposits Kept</th><th>Delivery Fees</th><th>Commission</th></tr></thead><tbody>";

    foreach ($quarter_data as $quarter => $data) {
        $rental_data = $rental_quarter_data[$quarter];
        $quarter_deposits_kept = floatval($rental_data['total_deposits']) - floatval($rental_data['total_deposits_returned']);
        $quarter_total_income = floatval($data['total_sales']) + floatval($rental_data['total_rentals']);
        
        $content .= "<tr>";
        $content .= "<td><strong>{$quarter}</strong></td>";
        $content .= "<td>$" . number_format($data['total_sales'], 2) . "</td>";
        $content .= "<td>$" . number_format($rental_data['total_rentals'], 2) . "</td>";
        $content .= "<td>$" . number_format($quarter_total_income, 2) . "</td>";
        $content .= "<td>$" . number_format($quarter_deposits_kept, 2) . "</td>";
        $content .= "<td>$" . number_format($data['total_delivery'], 2) . "</td>";
        $content .= "<td>$" . number_format($data['total_commission'], 2) . "</td>";
        $content .= "</tr>";
    }

    $content .= "</tbody></table></div>";
    break;

        case 'add_promotion':
            if (isset($_SESSION['success_message'])) {
                $content .= "<div class='alert alert-success'>" . $_SESSION['success_message'] . "</div>";
                unset($_SESSION['success_message']);
            }
            
            if (isset($_SESSION['error_message'])) {
                $content .= "<div class='alert alert-danger'>" . $_SESSION['error_message'] . "</div>";
                unset($_SESSION['error_message']);
            }
            
            $content .= "<h2 class='mt-4'>Add New Promotion</h2>";
        
            $content .= '<form method="post" action="?action=save_promotion" class="mb-4">
        
            <div class="form-group mb-3">
                <label for="item_id">Select Item:</label>
                <select name="item_id" id="item_id" class="form-control" required>
                    <option value="">-- Select Item --</option>';
        
            $conn = connectDB();
            $items = $conn->query("SELECT id, description FROM items WHERE status = 'active' ORDER BY description ASC");
            while ($item = $items->fetch_assoc()) {
                $content .= "<option value='{$item['id']}'>{$item['description']}</option>";
            }
            $conn->close();
        
            $content .= '</select>
            </div>
        
            <div class="form-group mb-3">
                <label for="platform">Platform:</label>
                <select name="platform" id="platform" class="form-control" required>
                    <option value="">-- Select Platform --</option>
                    <option value="Facebook Marketplace">Facebook Marketplace</option>
                    <option value="Facebook Business Page">Facebook Business Page</option>
                    <option value="Craigslist">Craigslist</option>
                    <option value="Google Local">Google Local</option>
                    <option value="Yelp">Yelp</option>
                    <option value="Google AdWords">Google AdWords</option>
                    <option value="Website">Website</option>
                    <option value="Equipment Trader">Equipment Trader</option>
                    <option value="Other">Other</option>
                </select>
            </div>
        
            <div class="form-group mb-3">
                <label for="promotion_type">Promotion Type:</label>
                <select name="promotion_type" id="promotion_type" class="form-control" required>
                    <option value="Free">Free</option>
                    <option value="Paid Ad">Paid Ad</option>
                    <option value="Subscription">Subscription</option>
                </select>
            </div>
        
            <div class="form-group mb-3">
                <label for="cost">Cost (if any):</label>
                <input type="number" step="0.01" min="0" name="cost" id="cost" class="form-control" placeholder="Enter cost (0.00 if free)" required>
            </div>
        
            <div class="form-group mb-4">
                <label for="billing_method">Billing Method:</label>
                <select name="billing_method" id="billing_method" class="form-control" required>
                    <option value="Free">Free</option>
                    <option value="One-Time">One-Time</option>
                    <option value="Per Click">Per Click</option>
                    <option value="Subscription">Subscription</option>
                </select>
            </div>
        
            <button type="submit" class="btn btn-success">Save Promotion</button>
            </form>';
        
            break;
        
            
            
            case 'rentals':
                $conn = connectDB();
                // Get available items for rental
                $sql_available = "
                    SELECT i.*, c.name AS consignor_name
                    FROM items i
                    JOIN consignors c ON i.consignor_id = c.id
                    WHERE i.status = 'active' AND i.rental_authorized = 1
                ";
                $result_available = $conn->query($sql_available);
                // Get active rentals
                $sql_rentals = "
                    SELECT r.*, i.description, i.make_model, c.name AS consignor_name
                    FROM rentals r
                    JOIN items i ON r.item_id = i.id
                    JOIN consignors c ON i.consignor_id = c.id
                    WHERE r.status = 'active'
                    ORDER BY r.rental_end ASC
                ";
                $result_rentals = $conn->query($sql_rentals);
                $content .= "<h2 class='mt-4'>RENTALS</h2>";
                
                
                // CURRENT RENTALS
                $content .= "<h3 class='mt-4'>Current Rentals</h3>";
                if ($result_rentals->num_rows > 0) {
                    $content .= "<table class='table table-striped'>";
                    $content .= "<thead><tr>
                                    <th>Equipment</th>
                                    <th>Rental Period</th>
                                    <th>Renter</th>
                                    <th>Daily Rate</th>
                                    <th>Total</th>
                                    <th>Actions</th>
                                </tr></thead><tbody>";
                    while ($row = $result_rentals->fetch_assoc()) {
                        $return_date = strtotime($row['rental_end']);
                        $today = strtotime(date('Y-m-d'));
                        if ($return_date < $today) {
                            $due_class = "table-danger";
                        } elseif ($return_date == $today) {
                            $due_class = "table-warning";
                        } else {
                            $due_class = "table-success";
                        }
                        $content .= "<tr class='{$due_class}'>";
                        $content .= "<td>{$row['description']}<br><small>{$row['make_model']}</small></td>";
                        $content .= "<td>" . date('m/d/Y', strtotime($row['rental_start'])) . " - " . date('m/d/Y', strtotime($row['rental_end'])) . "</td>";
                        $content .= "<td>{$row['renter_name']}<br><small>{$row['renter_contact']}</small></td>";
                        $content .= "<td>$" . number_format($row['daily_rate'], 2) . "</td>";
                        $content .= "<td>$" . number_format($row['total_amount'], 2) . "</td>";
                        $content .= "<td>
                                        <a href='?action=end_rental&rental_id={$row['id']}' class='btn btn-sm btn-warning'>End Rental</a>
                                        <a href='?action=generate_rental_invoice&rental_id={$row['id']}' class='btn btn-sm btn-primary'>Invoice</a>
                                        <a href='?action=delete_rental&id={$row['id']}' class='btn btn-sm btn-danger' onclick=\"return confirm('Are you sure you want to delete this rental record?')\">Delete</a>
                                     </td>";
                        $content .= "</tr>";
                    }
                    $content .= "</tbody></table>";
                } else {
                    $content .= "<p class='text-muted'>No active or upcoming rentals found.</p>";
                }
                
                // AVAILABLE EQUIPMENT
                $content .= "<h3 class='mt-4'>Available for Rental</h3>";
                $content .= "<p class='text-muted'>Click 'Rent This Item' next to the equipment to start a rental.</p>";
                if ($result_available->num_rows > 0) {
                    $content .= "<table class='table table-striped'>";
                    $content .= "<thead><tr>
                                    <th>Equipment</th>
                                    <th>Consignor</th>
                                    <th>Category</th>
                                    <th>Actions</th>
                                </tr></thead><tbody>";
                    while ($row = $result_available->fetch_assoc()) {
                        $content .= "<tr>";
                        $content .= "<td>{$row['description']}<br><small>{$row['make_model']}</small></td>";
                        $content .= "<td>{$row['consignor_name']}</td>";
                        $content .= "<td>{$row['category']}</td>";
                        $content .= "<td>
                                        <a href='?page=create_rental&item_id={$row['id']}' class='btn btn-sm btn-primary'>Rent This Item</a>
                                     </td>";
                        $content .= "</tr>";
                    }
                    $content .= "</tbody></table>";
                } else {
                    $content .= "<p class='text-muted'>No equipment available for rental at this time.</p>";
                }
                // COMPLETED RENTALS
           
            $sql_completed = "
                SELECT r.*, i.description, i.make_model, c.name AS consignor_name
                FROM rentals r
                JOIN items i ON r.item_id = i.id
                JOIN consignors c ON i.consignor_id = c.id
                WHERE r.status = 'completed'
                ORDER BY r.returned_on DESC
                LIMIT 2
            ";
            $result_completed = $conn->query($sql_completed);
            if ($result_completed->num_rows > 0) {
                $content .= "<a href='?page=completed_rentals' class='btn btn-danger mt-2'>View All Completed Rentals</a>";
            } else {
                $content .= "<p class='text-muted'>No completed rentals yet.</p>";
            }
            $conn->close();
            break;
            
                $conn = connectDB();
            
                // Get available items for rental
                $sql_available = "
                    SELECT i.*, c.name AS consignor_name
                    FROM items i
                    JOIN consignors c ON i.consignor_id = c.id
                    WHERE i.status = 'active' AND i.rental_authorized = 1
                ";
                $result_available = $conn->query($sql_available);
            
                // Get active rentals
                $sql_rentals = "
                    SELECT r.*, i.description, i.make_model, c.name AS consignor_name
                    FROM rentals r
                    JOIN items i ON r.item_id = i.id
                    JOIN consignors c ON i.consignor_id = c.id
                    WHERE r.status = 'active'
                    ORDER BY r.rental_end ASC
                ";
                $result_rentals = $conn->query($sql_rentals);
            
                $content .= "<h2 class='mt-4'>RENTALS</h2>";
            
                
                
                // CURRENT RENTALS
                $content .= "<h3>Current Rentals</h3>";
                if ($result_rentals->num_rows > 0) {
                    $content .= "<table class='table table-striped'>";
                    $content .= "<thead><tr>
                                    <th>Equipment</th>
                                    <th>Rental Period</th>
                                    <th>Renter</th>
                                    <th>Daily Rate</th>
                                    <th>Total</th>
                                    <th>Actions</th>
                                </tr></thead><tbody>";
            
                    while ($row = $result_rentals->fetch_assoc()) {
                        $return_date = strtotime($row['rental_end']);
                        $today = strtotime(date('Y-m-d'));
            
                        if ($return_date < $today) {
                            $due_class = "table-danger";
                        } elseif ($return_date == $today) {
                            $due_class = "table-warning";
                        } else {
                            $due_class = "table-success";
                        }
            
                        $content .= "<tr class='{$due_class}'>";
                        $content .= "<td>{$row['description']}<br><small>{$row['make_model']}</small></td>";
                        $content .= "<td>" . date('m/d/Y', strtotime($row['rental_start'])) . " - " . date('m/d/Y', strtotime($row['rental_end'])) . "</td>";
                        $content .= "<td>{$row['renter_name']}<br><small>{$row['renter_contact']}</small></td>";
                        $content .= "<td>$" . number_format($row['daily_rate'], 2) . "</td>";
                        $content .= "<td>$" . number_format($row['total_amount'], 2) . "</td>";
                        $content .= "<td>
                                        <a href='?action=end_rental&rental_id={$row['id']}' class='btn btn-sm btn-warning'>End Rental</a>
                                        <a href='?action=generate_rental_invoice&rental_id={$row['id']}' class='btn btn-sm btn-primary'>Invoice</a>
                                     </td>";
                        $content .= "</tr>";
                    }
            
                    $content .= "</tbody></table>";
                } else {
                    $content .= "<p class='text-muted'>No active or upcoming rentals found.</p>";
                }
            
                
                // AVAILABLE EQUIPMENT
                $content .= "<h3 class='mt-4'>Equipment Available for Rental</h3>";
                $content .= "<p class='text-muted'>Click 'Rent This Item' next to the equipment to start a rental.</p>";
            
                if ($result_available->num_rows > 0) {
                    $content .= "<table class='table table-striped'>";
                    $content .= "<thead><tr>
                                    <th>Equipment</th>
                                    <th>Consignor</th>
                                    <th>Category</th>
                                    <th>Actions</th>
                                </tr></thead><tbody>";
            
                    while ($row = $result_available->fetch_assoc()) {
                        $content .= "<tr>";
                        $content .= "<td>{$row['description']}<br><small>{$row['make_model']}</small></td>";
                        $content .= "<td>{$row['consignor_name']}</td>";
                        $content .= "<td>{$row['category']}</td>";
                        $content .= "<td>
                                        <a href='?page=create_rental&item_id={$row['id']}' class='btn btn-sm btn-primary'>Rent This Item</a>
                                     </td>";
                        $content .= "</tr>";
                    }
            
                    $content .= "</tbody></table>";
                } else {
                    $content .= "<p class='text-muted'>No equipment available for rental at this time.</p>";
                }
            
                // COMPLETED RENTALS
$content .= "<h3 class='mt-4'>Completed Rentals</h3>";
$sql_completed = "
    SELECT r.*, i.description, i.make_model, c.name AS consignor_name
    FROM rentals r
    JOIN items i ON r.item_id = i.id
    JOIN consignors c ON i.consignor_id = c.id
    WHERE r.status = 'completed'
    ORDER BY r.returned_on DESC
    LIMIT 2
";
$result_completed = $conn->query($sql_completed);
if ($result_completed->num_rows > 0) {
    $content .= "<table class='table table-sm table-bordered'>";
$content .= "<thead><tr>
                <th>Rental ID</th>
                <th>Item</th>
                <th>Renter</th>
                <th>Returned</th>
                <th>Inspection</th>
                <th>Notes</th>
                <th>Actions</th>
            </tr></thead><tbody>";
$content .= "<a href='?page=completed_rentals' class='btn btn-sm btn-secondary mt-2'>View All Completed Rentals</a>";
while ($row = $result_completed->fetch_assoc()) {
    $status_badge = $row['inspection_passed'] ? "<span class='badge badge-success'>Passed</span>" : "<span class='badge badge-danger'>Failed</span>";
    $rental_id_display = "R-" . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
    $content .= "<tr>";
    $content .= "<td><strong>{$rental_id_display}</strong></td>";
    $content .= "<td>{$row['description']}<br><small>{$row['make_model']}</small></td>";
    $content .= "<td>{$row['renter_name']}<br><small>{$row['renter_contact']}</small></td>";
    $content .= "<td>" . date('m/d/Y', strtotime($row['returned_on'])) . "</td>";
    $content .= "<td>{$status_badge}</td>";
    $content .= "<td>" . nl2br(htmlspecialchars($row['inspection_notes'])) . "</td>";
$content .= "<td>
    <a href='?action=delete_rental&id={$row['id']}' class='btn btn-sm btn-danger'
       onclick=\"return confirm('Are you sure you want to delete this completed rental record?')\">Delete</a>
</td>";
$content .= "</tr>";
}
$content .= "</tbody></table>";
} else {
    $content .= "<p class='text-muted'>No completed rentals yet.</p>";
}

$conn->close();
break;
echo displayPage($content);



case 'create_rental':
    unset($_SESSION['rental_preview']);
    $item_id = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;

    if ($item_id) {
        $conn = connectDB();
        $stmt = $conn->prepare("SELECT i.*, c.name AS consignor_name FROM items i JOIN consignors c ON i.consignor_id = c.id WHERE i.id = ? AND i.rental_authorized = 1");
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $item = $result->fetch_assoc();
            $content .= "<h2 class='mt-4'>Create Rental Agreement</h2>";
            $content .= "<div class='card mb-3'><div class='card-header'><strong>Equipment Details</strong></div><div class='card-body'>";
            $content .= "<p><strong>Description:</strong> {$item['description']}</p>";
            $content .= "<p><strong>Make/Model:</strong> {$item['make_model']}</p>";
            $content .= "<p><strong>Owner:</strong> {$item['consignor_name']}</p>";
            $content .= "</div></div>";

            $content .= "<form method='post' action='?action=process_rental&item_id={$item_id}' enctype='multipart/form-data' oninput='updateRentalPreview()'>";
            
            // Hidden tax fields for submission
            $content .= "<input type='hidden' name='tax_rate' id='tax_rate' value='0.0825'>";
            $content .= "<input type='hidden' name='tax_amount' id='tax_amount_hidden' value='0'>";
            $content .= "<input type='hidden' name='total_with_tax' id='total_with_tax_hidden' value='0'>";

            // Renter details
            $content .= "<div class='form-group'><label>Renter Name</label><input type='text' name='renter_name' id='renter_name' class='form-control' required></div>";
            $content .= "<div class='form-group'><label>Renter Phone</label><input type='text' name='renter_phone' id='renter_phone' class='form-control'></div>";
            $content .= "<div class='form-group'><label>Renter Email</label><input type='email' name='renter_email' id='renter_email' class='form-control'></div>";
            $content .= "<div class='form-group'><label>Renter Address</label><textarea name='renter_address' id='renter_address' class='form-control'></textarea></div>";
            $content .= "<div class='form-group'><label>Contact Person</label><input type='text' name='renter_contact' id='renter_contact' class='form-control' required></div>";
            $content .= "<div class='form-group'><label>ID / License #</label><input type='text' name='renter_id_number' id='renter_id_number' class='form-control'></div>";
            $content .= "<div class='form-group'><label>License State</label><input type='text' name='license_state' id='license_state' maxlength='2' class='form-control'></div>";

            $content .= "<div class='form-group'><label>Upload Buyer's Driver's License:</label><input type='file' name='drivers_license' class='form-control-file' accept='image/*,.pdf' required>
                <small class='form-text text-muted'>Valid, unexpired photo ID required for all rentals. PDF or image only.<br>
                <input type='checkbox' name='not_military_id' required> I confirm this is not a military ID or Common Access Card (CAC).<br>
                <strong>Federal law prohibits storing or copying U.S. military IDs.</strong> Please provide a state-issued ID or driver's license instead.</small>
            </div>";

            $content .= "<div class='form-group'><label>Daily Rate ($)</label><input type='number' name='daily_rate' id='daily_rate' step='0.01' class='form-control' required></div>";
            $content .= "<div class='form-group'><label>Refundable Deposit Collected ($) <span class='text-danger'>(Required Upfront)</span></label><input type='number' name='deposit_amount' id='deposit_amount' step='0.01' class='form-control' required></div>";
            $content .= "<div class='form-group'><label>Scheduled Pickup or Delivery Date/Time</label><input type='datetime-local' name='scheduled_pickup' id='scheduled_pickup' class='form-control' required></div>";
            $content .= "<div class='form-group'><label>Scheduled Return Date/Time</label><input type='datetime-local' name='scheduled_return' id='scheduled_return' class='form-control' required></div>";

            $content .= "<div class='form-group'><label>Pickup and Delivery Options:</label>
                <div class='form-check'><input class='form-check-input' type='checkbox' name='pickup_required' id='pickup_required'>
                <label class='form-check-label' for='pickup_required'>Free Pickup (within 30 miles, 16ft trailer max, profit ≥ $75)</label></div>
                <div class='form-check'><input class='form-check-input' type='checkbox' name='delivery_required' id='delivery_required'>
                <label class='form-check-label' for='delivery_required'>Flat-Rate Delivery ($50)</label></div>
            </div>";

            $content .= "<div class='form-group'><label>Delivery Phone</label><input type='text' name='delivery_phone' id='delivery_phone' class='form-control'></div>";
            $content .= "<div class='form-group'><label>Delivery Address</label><textarea name='delivery_address' id='delivery_address' class='form-control'></textarea></div>";
            $content .= "<div class='form-group'><label>One-Way Mileage (for delivery/pickup)</label><input type='number' name='mileage' id='mileage' step='0.1' class='form-control'></div>";

            $content .= "<div class='card mt-4'><div class='card-header'><strong>Rental Summary Preview</strong></div><div class='card-body' id='rental-preview'><p>Live preview will appear here.</p></div></div>";

            $content .= "<button type='submit' class='btn btn-success mt-4'>Generate Rental Invoice</button></form>";

            $content .= <<<EOT
<script>
function updateRentalPreview() {
    const dailyRate = parseFloat(document.getElementById('daily_rate').value) || 0;
    const pickupStr = document.getElementById('scheduled_pickup').value;
    const returnStr = document.getElementById('scheduled_return').value;
    const startDate = pickupStr ? new Date(pickupStr) : null;
    const endDate = returnStr ? new Date(returnStr) : null;

    const pickup = document.getElementById('pickup_required').checked;
    const delivery = document.getElementById('delivery_required').checked;
    const mileage = parseFloat(document.getElementById('mileage').value) || 0;
    
    // Fixed tax rate
    const taxRate = 0.0825; // 8.25%

    let days = 0;
    if (startDate && endDate && endDate >= startDate) {
        const timeDiff = endDate.getTime() - startDate.getTime();
        days = Math.floor(timeDiff / (1000 * 60 * 60 * 24)) + 1;
    }

    const subtotal = days * dailyRate;
    const deliveryFee = (delivery && mileage <= 30 && subtotal >= 75) ? 50 : 0;
    
    // Calculate tax on subtotal + delivery fee
    const taxableAmount = subtotal + deliveryFee;
    const taxAmount = taxableAmount * taxRate;
    const total = taxableAmount + taxAmount;

    // Update hidden fields for form submission
    document.getElementById('tax_amount_hidden').value = taxAmount.toFixed(2);
    document.getElementById('total_with_tax_hidden').value = total.toFixed(2);

    function fmt(n) { return '$' + n.toFixed(2); }

    let html = "<p><strong>Days:</strong> " + days + "</p>";
    html += "<p><strong>Daily Rate:</strong> " + fmt(dailyRate) + "</p>";
    html += "<p><strong>Subtotal:</strong> " + fmt(subtotal) + "</p>";
    
    if (delivery) {
        html += "<p><strong>Delivery Required:</strong> Yes</p>";
        if (deliveryFee > 0) {
            html += "<p><strong>Delivery Fee:</strong> " + fmt(deliveryFee) + "</p>";
        } else {
            html += "<p><strong>Delivery Fee:</strong> Not eligible (Mileage > 30 or Subtotal < $75)</p>";
        }
    }
    
    // Always show tax calculation
    html += "<p><strong>Sales Tax (8.25%):</strong> " + fmt(taxAmount) + "</p>";
    html += "<p class='font-weight-bold'><strong>Total Due:</strong> " + fmt(total) + "</p>";

    document.getElementById('rental-preview').innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function () {
    updateRentalPreview();
    const inputs = document.querySelectorAll(
        '#daily_rate, #deposit_amount, #scheduled_pickup, #scheduled_return, #delivery_required, #pickup_required, #mileage'
    );
    inputs.forEach(input => input.addEventListener('input', updateRentalPreview));
    inputs.forEach(input => input.addEventListener('change', updateRentalPreview));
});
</script>
EOT;

        } else {
            $content .= "<div class='alert alert-danger'>Item not found or not available for rental.</div>";
        }
        $stmt->close();
        $conn->close();
    } else {
        $content .= "<div class='alert alert-warning'>No item selected for rental.</div>";
    }
    break;

                    case 'rental_history':
        if (!isset($_GET['consignor_id']) || !is_numeric($_GET['consignor_id'])) {
            $content .= "<div class='alert alert-danger'>Invalid Consignor ID.</div>";
            break;
        }
    
        $consignor_id = (int) $_GET['consignor_id'];
        $conn = connectDB();
    
        $sql = "
            SELECT r.*, i.description, i.make_model
            FROM rentals r
            JOIN items i ON r.item_id = i.id
            WHERE i.consignor_id = ?
            ORDER BY r.rental_start DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $consignor_id);
        $stmt->execute();
        $result = $stmt->get_result();
    
        $content .= "<div class='d-flex justify-content-between align-items-center mb-3'>";
        $content .= "<h2 class='mb-0'>Rental History for Consignor #{$consignor_id}</h2>";
        $content .= "<div>
            <a href='?action=export_rental_history_csv&consignor_id={$consignor_id}' class='btn btn-sm btn-primary'>Export CSV</a>
            <a href='?action=export_rental_history_pdf&consignor_id={$consignor_id}' class='btn btn-sm btn-secondary ml-2'>Export PDF</a>
        </div>";
        $content .= "</div>";
    
        if ($result->num_rows > 0) {
            $content .= "<table class='table table-sm table-bordered'>";
            $content .= "<thead><tr>
                <th>Date</th>
                <th>Rental ID</th>
                <th>Item</th>
                <th>Renter</th>
                <th>Phone</th>
                <th>Email</th>
                <th>ID / License #</th>
                <th>State</th>
                <th>License File</th>
                <th>Total</th>
                <th>Status</th>
            </tr></thead><tbody>";
    
            $total = 0;
            while ($row = $result->fetch_assoc()) {
                $rental_id = "R-" . str_pad($row['id'], 5, "0", STR_PAD_LEFT);
                $badge = $row['status'] === 'completed'
                    ? "<span class='badge badge-success'>Completed</span>"
                    : "<span class='badge badge-warning'>Active</span>";
                $license_link = $row['license_file']
                    ? "<a href='{$row['license_file']}' target='_blank'>View</a>"
                    : "<span class='text-muted'>&mdash;</span>";
    
                $content .= "<tr>";
                $content .= "<td>" . date("m/d/Y", strtotime($row['rental_start'])) . "</td>";
                $content .= "<td>{$rental_id}</td>";
                $content .= "<td>{$row['description']}<br><small>{$row['make_model']}</small></td>";
                $content .= "<td>{$row['renter_name']}<br><small>{$row['renter_contact']}</small></td>";
                $content .= "<td>{$row['renter_phone']}</td>";
                $content .= "<td>{$row['renter_email']}</td>";
                $content .= "<td>{$row['renter_id_number']}</td>";
                $content .= "<td>{$row['license_state']}</td>";
                $content .= "<td>{$license_link}</td>";
                $content .= "<td>$" . number_format($row['total_amount'], 2) . "</td>";
                $content .= "<td>{$badge}</td>";
                $content .= "</tr>";
    
                $total += $row['total_amount'];
            }
    
            $content .= "</tbody><tfoot>
                <tr class='table-active'>
                    <td colspan='9'><strong>Total Income:</strong></td>
                    <td colspan='2'><strong>$" . number_format($total, 2) . "</strong></td>
                </tr>
            </tfoot></table>";
        } else {
            $content .= "<p class='text-muted'>No rental records found for this consignor.</p>";
        }
    
        $stmt->close();
        $conn->close();
        break;
                    
  
                        case 'completed_rentals':
                    $conn = connectDB();
                
                    $limitOptions = [10, 25, 50, 100, 200];
                    $limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limitOptions) ? (int)$_GET['limit'] : 10;
                    $pageNum = isset($_GET['p']) ? (int)$_GET['p'] : 1;
                    $offset = ($pageNum - 1) * $limit;
                
                    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
                    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
                    $date_filter_sql = '';
                
                    if (!empty($start_date) && !empty($end_date)) {
                        $date_filter_sql = " AND returned_on BETWEEN '{$start_date}' AND '{$end_date}'";
                    }
                
                    $countSql = "SELECT COUNT(*) as total FROM rentals WHERE status = 'completed' AND is_archived = 0 {$date_filter_sql}";
                    $countResult = $conn->query($countSql);
                    $totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
                    $totalPages = ceil($totalRows / $limit);
                
                    $content .= "<h2 class='mt-4'>All Completed Rentals</h2>";
                    $content .= "<form method='get' class='form-inline mb-3'>
                        <input type='hidden' name='page' value='completed_rentals'>
                        <label class='mr-2'>From:</label>
                        <input type='date' name='start_date' class='form-control form-control-sm mr-2' value='{$start_date}'>
                        <label class='mr-2'>To:</label>
                        <input type='date' name='end_date' class='form-control form-control-sm mr-2' value='{$end_date}'>
                        <label class='mr-2'>Show:</label>
                        <select name='limit' class='form-control form-control-sm mr-2' onchange='this.form.submit()'>";
                    foreach ($limitOptions as $opt) {
                        $selected = ($limit == $opt) ? "selected" : "";
                        $content .= "<option value='{$opt}' {$selected}>{$opt}</option>";
                    }
                    $content .= "</select>
                        <button type='submit' class='btn btn-sm btn-primary'>Apply Filter</button>
                    </form>";
                
                    $content .= "<div class='row mb-3'>
                        <div class='col-md-6'></div>
                        <div class='col-md-6 text-right'>
                            <a href='?page=archived_rentals' class='btn btn-warning'>View Archived Rentals</a>
                            <a href='?page=refunds&msg=issued' class='btn btn-danger'>Refund History</a>
                            <a href='?action=export_completed_rentals_csv&limit={$limit}&p={$pageNum}&start_date={$start_date}&end_date={$end_date}' class='btn btn-primary ml-2'>Export to CSV</a>
                            <a href='?action=export_completed_rentals_pdf&limit={$limit}&p={$pageNum}&start_date={$start_date}&end_date={$end_date}' class='btn btn-secondary ml-2'>Export to PDF</a>
                        </div>
                    </div>";
                
                    $sql = "
                        SELECT r.*, i.description, i.make_model, c.name AS consignor_name
                        FROM rentals r
                        JOIN items i ON r.item_id = i.id
                        JOIN consignors c ON i.consignor_id = c.id
                        WHERE r.status = 'completed' AND r.is_archived = 0 {$date_filter_sql}
                        ORDER BY r.returned_on DESC
                        LIMIT {$limit} OFFSET {$offset}
                    ";
                    $result = $conn->query($sql);
                
                    $total_income = 0;
                    $rows = [];
                
                    while ($row = $result->fetch_assoc()) {
                        $total_income += $row['total_amount'];
                        $rows[] = $row;
                    }
                
                    if (count($rows) > 0) {
                        $content .= "<div class='alert alert-info'>
                            Showing <strong>" . count($rows) . "</strong> rentals &mdash; Total Income: <strong>$" . number_format($total_income, 2) . "</strong>
                        </div>";
                
                        $content .= "<table class='table table-sm table-bordered'>
                            <thead><tr>
                                <th>Rental ID</th>
                                <th>Item</th>
                                <th>Renter</th>
                                <th>Returned</th>
                                <th>Inspection</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr></thead><tbody>";
                
                        foreach ($rows as $row) {
                            $badge = $row['inspection_passed'] ? "<span class='badge badge-success'>Passed</span>" : "<span class='badge badge-danger'>Failed</span>";
                            $rental_id = "R-" . str_pad($row['id'], 5, "0", STR_PAD_LEFT);
                            $content .= "<tr>
                                <td>{$rental_id}</td>
                                <td>{$row['description']}<br><small>{$row['make_model']}</small></td>
                                <td>{$row['renter_name']}<br><small>{$row['renter_contact']}</small></td>
                                <td>" . date('m/d/Y', strtotime($row['returned_on'])) . "</td>
                                <td>{$badge}</td>
                                <td>" . nl2br(htmlspecialchars($row['inspection_notes'])) . "</td>
                                <td>
                                    <a href='?action=archive_rental&id={$row['id']}' class='btn btn-sm btn-warning mb-1' title='Archive' onclick=\"return confirm('Archive this rental from the completed list?')\">Archive</a>
                                    
<a href='?action=print_return_receipt&rental_id={$row['id']}' target='_blank' class='btn btn-sm btn-success'>Print Receipt</a>

                                    <a href='?action=issue_refund&type=rental&id={$row['id']}' class='btn btn-sm btn-danger' title='Refund' onclick=\"return confirm('Issue refund for this rental?')\">Refund</a>
                                </td>
                            </tr>";
                        }
                
                        $content .= "</tbody></table>";
                    } else {
                        $content .= "<p class='text-muted'>No completed rentals found.</p>";
                    }
                
                    if ($totalPages > 1) {
                        $content .= "<nav><ul class='pagination'>";
                        for ($i = 1; $i <= $totalPages; $i++) {
                            $active = ($i == $pageNum) ? "active" : "";
                            $content .= "<li class='page-item {$active}'><a class='page-link' href='?page=completed_rentals&limit={$limit}&p={$i}&start_date={$start_date}&end_date={$end_date}'>{$i}</a></li>";
                        }
                        $content .= "</ul></nav>";
                    }
                
                    $conn->close();
                    break;


                    case 'archived_rentals':
        $conn = connectDB();
    
        $limitOptions = [10, 25, 50, 100, 200];
        $limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], $limitOptions) ? (int)$_GET['limit'] : 10;
        $pageNum = isset($_GET['p']) ? (int)$_GET['p'] : 1;
        $offset = ($pageNum - 1) * $limit;
    
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
        $date_filter_sql = '';
    
        if (!empty($start_date) && !empty($end_date)) {
            $date_filter_sql = " AND returned_on BETWEEN '{$start_date}' AND '{$end_date}'";
        }
    
        $countSql = "SELECT COUNT(*) as total FROM rentals WHERE status = 'completed' AND is_archived = 1 {$date_filter_sql}";
        $countResult = $conn->query($countSql);
        $totalRows = ($countResult && $countResult->num_rows > 0) ? $countResult->fetch_assoc()['total'] : 0;
        $totalPages = ceil($totalRows / $limit);
    
        $content .= "<h2 class='mt-4'>Archived Rentals</h2>";
        $content .= "<form method='get' class='form-inline mb-3'>
            <input type='hidden' name='page' value='archived_rentals'>
            <label class='mr-2'>From:</label>
            <input type='date' name='start_date' class='form-control form-control-sm mr-2' value='{$start_date}'>
            <label class='mr-2'>To:</label>
            <input type='date' name='end_date' class='form-control form-control-sm mr-2' value='{$end_date}'>
            <label class='mr-2'>Show:</label>
            <select name='limit' class='form-control form-control-sm mr-2' onchange='this.form.submit()'>";
    
        foreach ($limitOptions as $opt) {
            $selected = ($limit == $opt) ? "selected" : "";
            $content .= "<option value='{$opt}' {$selected}>{$opt}</option>";
        }
    
        $content .= "</select>
            <button type='submit' class='btn btn-sm btn-primary'>Apply Filter</button>
        </form>";
    
        $sql = "
            SELECT r.*, i.description, i.make_model, c.name AS consignor_name
            FROM rentals r
            JOIN items i ON r.item_id = i.id
            JOIN consignors c ON i.consignor_id = c.id
            WHERE r.status = 'completed' AND r.is_archived = 1 {$date_filter_sql}
            ORDER BY r.returned_on DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $result = $conn->query($sql);
    
        $rows = [];
        $total_income = 0;
    
        while ($row = $result->fetch_assoc()) {
            $total_income += $row['total_amount'];
            $rows[] = $row;
        }
    
        if (count($rows) > 0) {
            $content .= "<div class='alert alert-warning'>
                Showing <strong>" . count($rows) . "</strong> archived rentals &mdash; Total Income: <strong>$" . number_format($total_income, 2) . "</strong>
            </div>";
    
            $content .= "<table class='table table-sm table-bordered'>
                <thead><tr>
                    <th>Rental ID</th>
                    <th>Item</th>
                    <th>Renter</th>
                    <th>Returned</th>
                    <th>Inspection</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr></thead><tbody>";
    
            foreach ($rows as $row) {
                $badge = $row['inspection_passed'] ? "<span class='badge badge-success'>Passed</span>" : "<span class='badge badge-danger'>Failed</span>";
                $rental_id = "R-" . str_pad($row['id'], 5, "0", STR_PAD_LEFT);
                $content .= "<tr>
                    <td>{$rental_id}</td>
                    <td>{$row['description']}<br><small>{$row['make_model']}</small></td>
                    <td>{$row['renter_name']}<br><small>{$row['renter_contact']}</small></td>
                    <td>" . date('m/d/Y', strtotime($row['returned_on'])) . "</td>
                    <td>{$badge}</td>
                    <td>" . nl2br(htmlspecialchars($row['inspection_notes'])) . "</td>
                    <td>
                        <a href='?action=restore_rental&id={$row['id']}' class='btn btn-sm btn-success' onclick=\"return confirm('Restore this rental to active records?')\">Restore</a>
                        <a href='?action=delete_rental&id={$row['id']}' class='btn btn-sm btn-danger' onclick=\"return confirm('Permanently delete this rental?')\">Delete</a>
                    </td>
                </tr>";
            }
    
            $content .= "</tbody></table>";
        } else {
            $content .= "<p class='text-muted'>No archived rentals found.</p>";
        }
    
        if ($totalPages > 1) {
            $content .= "<nav><ul class='pagination'>";
            for ($i = 1; $i <= $totalPages; $i++) {
                $active = ($i == $pageNum) ? "active" : "";
                $content .= "<li class='page-item {$active}'><a class='page-link' href='?page=archived_rentals&limit={$limit}&p={$i}&start_date={$start_date}&end_date={$end_date}'>{$i}</a></li>";
            }
            $content .= "</ul></nav>";
        }
    
        $conn->close();
        break;



                        
            case 'edit_item':
    if (isset($_GET['item_id'])) {
        $conn = connectDB();
        $item_id = (int) $_GET['item_id'];

        $stmt = $conn->prepare("SELECT i.*, c.name AS consignor_name FROM items i 
                              LEFT JOIN consignors c ON i.consignor_id = c.id 
                              WHERE i.id = ?");
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $item = $result->fetch_assoc();

        if ($item) {
            // Get consignors for dropdown
            $consignors_stmt = $conn->prepare("SELECT id, name FROM consignors ORDER BY name");
            $consignors_stmt->execute();
            $consignors_result = $consignors_stmt->get_result();
            
            $content .= "<h2 class='mt-4'>Edit Item</h2>";
            $content .= "<form action='?action=update_item' method='post' class='form'>";
            $content .= "<input type='hidden' name='id' value='{$item['id']}'>";

            $content .= "<div class='form-group'>";
            $content .= "<label>Description:</label>";
            $content .= "<input type='text' name='description' class='form-control' value='" . htmlspecialchars($item['description']) . "'>";
            $content .= "</div>";

            $content .= "<div class='form-group'>";
            $content .= "<label>Make/Model:</label>";
            $content .= "<input type='text' name='make_model' class='form-control' value='" . htmlspecialchars($item['make_model']) . "'>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label>Serial Number:</label>";
            $content .= "<input type='text' name='serial_number' class='form-control' value='" . htmlspecialchars($item['serial_number']) . "'>";
            $content .= "</div>";
            
            // Get consignor name for house inventory check
            $is_house_inventory = ($item['consignor_name'] === 'House Inventory');
            
            // House Inventory specific fields
            $content .= "<div id='house_inventory_fields' " . ($is_house_inventory ? "" : "style='display:none;'") . ">";
            $content .= "<h4 class='mt-3'>House Inventory Details</h4>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='purchase_source'>Purchase Source:</label>";
            $content .= "<input type='text' name='purchase_source' id='purchase_source' class='form-control' value='" . htmlspecialchars($item['purchase_source'] ?? '') . "' placeholder='Where was this item purchased from?'>";
            $content .= "</div>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='purchase_price'>Purchase Price ($):</label>";
            $content .= "<input type='number' name='purchase_price' id='purchase_price' step='0.01' class='form-control' value='" . ($item['purchase_price'] ?? '') . "'>";
            $content .= "</div>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='date_acquired'>Date Acquired:</label>";
            $date_acquired = !empty($item['date_acquired']) && $item['date_acquired'] != '0000-00-00' 
                ? date('Y-m-d', strtotime($item['date_acquired'])) : '';
            $content .= "<input type='date' name='date_acquired' id='date_acquired' class='form-control' value='{$date_acquired}'>";
            $content .= "</div>";
            $content .= "</div>";
            
            // Condition & Disclosures card
            $content .= "<div class='card mb-3'>";
            $content .= "<div class='card-header'><h4>Item Condition & Disclosures</h4></div>";
            $content .= "<div class='card-body'>";
            
            // Basic condition field (existing)
            $content .= "<div class='form-group'>";
            $content .= "<label for='condition_desc'>General Condition:</label>";
            $content .= "<textarea name='condition_desc' id='condition_desc' class='form-control' placeholder='Describe the overall condition of the item'>" . htmlspecialchars($item['condition_desc'] ?? '') . "</textarea>";
            $content .= "</div>";
            
            // New detailed disclosure fields
            $content .= "<div class='form-group'>";
            $content .= "<label for='hours_used'>Hours Used (if applicable):</label>";
            $content .= "<input type='number' name='hours_used' id='hours_used' class='form-control' value='" . ($item['hours_used'] ?? '') . "'>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='maintenance_history'>Maintenance History:</label>";
            $content .= "<textarea name='maintenance_history' id='maintenance_history' class='form-control' placeholder='Service records, past repairs, etc.'>" . htmlspecialchars($item['maintenance_history'] ?? '') . "</textarea>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='last_maintenance_date'>Last Maintenance Date:</label>";
            $maint_date = !empty($item['last_maintenance_date']) && $item['last_maintenance_date'] != '0000-00-00' 
                ? date('Y-m-d', strtotime($item['last_maintenance_date'])) : '';
            $content .= "<input type='date' name='last_maintenance_date' id='last_maintenance_date' class='form-control' value='{$maint_date}'>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='known_issues'>Known Issues/Defects:</label>";
            $content .= "<textarea name='known_issues' id='known_issues' class='form-control' placeholder='Any known defects, leaks, bad tires, etc.'>" . htmlspecialchars($item['known_issues'] ?? '') . "</textarea>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='wear_description'>Signs of Wear/Damage:</label>";
            $content .= "<textarea name='wear_description' id='wear_description' class='form-control' placeholder='Visible scratches, dents, wear and tear, etc.'>" . htmlspecialchars($item['wear_description'] ?? '') . "</textarea>";
            $content .= "</div>";
            
            $content .= "</div>"; // End card-body
            $content .= "</div>"; // End card

            // Add Title Information Section
            $content .= "<div class='card mb-3'>";
            $content .= "<div class='card-header'>";
            $content .= "<div class='d-flex justify-content-between align-items-center'>";
            $content .= "<h4 class='mb-0'>Title Information</h4>";
            $content .= "<div class='custom-control custom-switch'>";
            $is_titled_checked = isset($item['is_titled']) && $item['is_titled'] == 1 ? 'checked' : '';
            $content .= "<input type='checkbox' class='custom-control-input' id='is_titled' name='is_titled' value='1' {$is_titled_checked} onchange='toggleTitleFields()'>";
            $content .= "<label class='custom-control-label' for='is_titled'>This item is titled</label>";
            $content .= "</div>";
            $content .= "</div>";
            $content .= "</div>";

            $title_fields_display = isset($item['is_titled']) && $item['is_titled'] == 1 ? 'block' : 'none';
            $content .= "<div class='card-body' id='title_fields' style='display:{$title_fields_display};'>";
            $content .= "<div class='alert alert-info'>";
            $content .= "<i class='fas fa-info-circle'></i> Title information is required for vehicles, trailers, and other DMV-registered equipment.";
            $content .= "</div>";

            $content .= "<div class='row'>";
            $content .= "<div class='col-md-6'>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='title_number'>Title Number:</label>";
            $content .= "<input type='text' name='title_number' id='title_number' class='form-control' placeholder='DMV title number/reference' value='" . htmlspecialchars($item['title_number'] ?? '') . "'>";
            $content .= "</div>";
            $content .= "</div>";

            $content .= "<div class='col-md-6'>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='title_state'>Title State:</label>";
            $content .= "<select name='title_state' id='title_state' class='form-control'>";
            $content .= "<option value=''>-- Select State --</option>";
            $states = [
                'AL'=>'Alabama', 'AK'=>'Alaska', 'AZ'=>'Arizona', 'AR'=>'Arkansas', 'CA'=>'California',
                'CO'=>'Colorado', 'CT'=>'Connecticut', 'DE'=>'Delaware', 'FL'=>'Florida', 'GA'=>'Georgia',
                'HI'=>'Hawaii', 'ID'=>'Idaho', 'IL'=>'Illinois', 'IN'=>'Indiana', 'IA'=>'Iowa',
                'KS'=>'Kansas', 'KY'=>'Kentucky', 'LA'=>'Louisiana', 'ME'=>'Maine', 'MD'=>'Maryland',
                'MA'=>'Massachusetts', 'MI'=>'Michigan', 'MN'=>'Minnesota', 'MS'=>'Mississippi', 'MO'=>'Missouri',
                'MT'=>'Montana', 'NE'=>'Nebraska', 'NV'=>'Nevada', 'NH'=>'New Hampshire', 'NJ'=>'New Jersey',
                'NM'=>'New Mexico', 'NY'=>'New York', 'NC'=>'North Carolina', 'ND'=>'North Dakota', 'OH'=>'Ohio',
                'OK'=>'Oklahoma', 'OR'=>'Oregon', 'PA'=>'Pennsylvania', 'RI'=>'Rhode Island', 'SC'=>'South Carolina',
                'SD'=>'South Dakota', 'TN'=>'Tennessee', 'TX'=>'Texas', 'UT'=>'Utah', 'VT'=>'Vermont',
                'VA'=>'Virginia', 'WA'=>'Washington', 'WV'=>'West Virginia', 'WI'=>'Wisconsin', 'WY'=>'Wyoming',
                'DC'=>'District of Columbia'
            ];
            foreach($states as $abbr => $state) {
                $selected = (isset($item['title_state']) && $item['title_state'] === $abbr) ? 'selected' : '';
                $content .= "<option value='{$abbr}' {$selected}>{$state}</option>";
            }
            $content .= "</select>";
            $content .= "</div>";
            $content .= "</div>";
            $content .= "</div>";

            $content .= "<div class='row'>";
            $content .= "<div class='col-md-6'>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='vin'>VIN/Serial:</label>";
            $content .= "<input type='text' name='vin' id='vin' class='form-control' placeholder='Vehicle Identification Number' value='" . htmlspecialchars($item['vin'] ?? '') . "'>";
            $content .= "<small class='form-text text-muted'>For vehicles, enter the 17-digit VIN. For other equipment, enter the serial number.</small>";
            $content .= "</div>";
            $content .= "</div>";

            $content .= "<div class='col-md-6'>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='title_status'>Title Status:</label>";
            $content .= "<select name='title_status' id='title_status' class='form-control'>";
            $title_statuses = ['clear' => 'Clear Title', 'lien' => 'Has Lien', 'salvage' => 'Salvage Title', 
                             'rebuilt' => 'Rebuilt/Reconstructed', 'bonded' => 'Bonded Title', 'pending' => 'Pending/In Process'];
            foreach ($title_statuses as $value => $label) {
                $selected = (isset($item['title_status']) && $item['title_status'] === $value) ? 'selected' : '';
                $content .= "<option value='{$value}' {$selected}>{$label}</option>";
            }
            $content .= "</select>";
            $content .= "</div>";
            $content .= "</div>";
            $content .= "</div>";

            $content .= "<div class='row'>";
            $content .= "<div class='col-md-6'>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='title_holder'>Title Holder (if not consignor):</label>";
            $content .= "<input type='text' name='title_holder' id='title_holder' class='form-control' value='" . htmlspecialchars($item['title_holder'] ?? '') . "'>";
            $content .= "</div>";
            $content .= "</div>";

            $content .= "<div class='col-md-6'>";
            $content .= "<div class='form-group'>";
            $content .= "<label for='title_issue_date'>Title Issue Date:</label>";
            $title_date = !empty($item['title_issue_date']) && $item['title_issue_date'] != '0000-00-00' 
                ? date('Y-m-d', strtotime($item['title_issue_date'])) : '';
            $content .= "<input type='date' name='title_issue_date' id='title_issue_date' class='form-control' value='{$title_date}'>";
            $content .= "</div>";
            $content .= "</div>";
            $content .= "</div>";

            $title_in_possession_checked = isset($item['title_in_possession']) && $item['title_in_possession'] == 1 ? 'checked' : '';
            $content .= "<div class='form-check mt-3'>";
            $content .= "<input type='checkbox' name='title_in_possession' id='title_in_possession' value='1' class='form-check-input' {$title_in_possession_checked}>";
            $content .= "<label for='title_in_possession' class='form-check-label'>We have physical possession of the title</label>";
            $content .= "</div>";

            $content .= "<div class='alert alert-warning mt-3'>";
            $content .= "<strong>Important:</strong> For titled items, we must have the original title in our possession before sale, or proper transfer documentation completed.";
            $content .= "</div>";

            $content .= "</div>"; // End card-body
            $content .= "</div>"; // End title information card

            $content .= "<div class='form-group'>";
            $content .= "<label>Category:</label>";
            $content .= "<select name='category' class='form-control'>";
            $content .= "<option value='Standard' " . ($item['category'] == 'Standard' ? 'selected' : '') . ">Standard Equipment</option>";
            $content .= "<option value='Trailer' " . ($item['category'] == 'Trailer' ? 'selected' : '') . ">Trailer</option>";
            $content .= "<option value='Tractors & Mowers' " . ($item['category'] == 'Tractors & Mowers' ? 'selected' : '') . ">Tractors & Mowers</option>";
            $content .= "<option value='Tools & Small Gear' " . ($item['category'] == 'Tools & Small Gear' ? 'selected' : '') . ">Tools & Small Gear</option>";
            $content .= "</select>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label>Asking Price ($):</label>";
            $content .= "<input type='number' name='asking_price' step='0.01' class='form-control' value='" . $item['asking_price'] . "'>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label>Minimum Price ($):</label>";
            $content .= "<input type='number' name='min_price' step='0.01' class='form-control' value='" . $item['min_price'] . "'>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label>Consignor:</label>";
            $content .= "<select name='consignor_id' id='consignor_id' class='form-control'>";
            while ($consignor = $consignors_result->fetch_assoc()) {
                $selected = ($consignor['id'] == $item['consignor_id']) ? 'selected' : '';
                $content .= "<option value='{$consignor['id']}' {$selected}>{$consignor['name']}</option>";
            }
            $content .= "</select>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='rental_authorized'>Rental Authorized:</label>";
            $rental_selected = isset($item['rental_authorized']) ? (int)$item['rental_authorized'] : 0;
            $content .= "<select name='rental_authorized' id='rental_authorized' class='form-control'>";
            $content .= "<option value='0' " . ($rental_selected == 0 ? 'selected' : '') . ">No</option>";
            $content .= "<option value='1' " . ($rental_selected == 1 ? 'selected' : '') . ">Yes</option>";
            $content .= "</select>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='trade_authorized'>Trade Authorized:</label>";
            $trade_selected = isset($item['is_trade_authorized']) ? (int)$item['is_trade_authorized'] : 0;
            $content .= "<select name='trade_authorized' id='trade_authorized' class='form-control'>";
            $content .= "<option value='0' " . ($trade_selected == 0 ? 'selected' : '') . ">No</option>";
            $content .= "<option value='1' " . ($trade_selected == 1 ? 'selected' : '') . ">Yes</option>";
            $content .= "</select>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label>Status:</label>";
            $content .= "<select name='status' class='form-control'>";
            $content .= "<option value='active' " . ($item['status'] == 'active' ? 'selected' : '') . ">Active</option>";
            $content .= "<option value='sold' " . ($item['status'] == 'sold' ? 'selected' : '') . ">Sold</option>";
            $content .= "<option value='pickup' " . ($item['status'] == 'pickup' ? 'selected' : '') . ">For Pickup</option>";
            $content .= "</select>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label>Notes:</label>";
            $content .= "<textarea name='notes' class='form-control'>" . htmlspecialchars($item['notes']) . "</textarea>";
            $content .= "</div>";

            $content .= "<div class='form-group'><label>Pickup Phone</label>
            <input type='text' name='pickup_phone' class='form-control' value='" . htmlspecialchars($item['pickup_phone'] ?? '') . "'></div>";

            $content .= "<div class='form-group'><label>Pickup Address</label>
            <textarea name='pickup_address' class='form-control'>" . htmlspecialchars($item['pickup_address'] ?? '') . "</textarea></div>";

            $content .= "<div class='form-group'><label>One-Way Mileage</label>
            <input type='number' step='0.1' name='mileage' class='form-control' value='" . htmlspecialchars($item['mileage'] ?? '') . "'></div>";

            $value = !empty($item['scheduled_pickup']) ? date('Y-m-d\TH:i', strtotime($item['scheduled_pickup'])) : '';

            $content .= "<div class='form-group'><label>Scheduled Pickup</label>";
            $content .= "<input type='datetime-local' name='scheduled_pickup' class='form-control' value='{$value}'>";
            $content .= "</div>";

            $formatted_pickup = '';
            if (!empty($item['scheduled_pickup']) && $item['scheduled_pickup'] !== '0000-00-00 00:00:00') {
                $dt = new DateTime($item['scheduled_pickup']);
                $formatted_pickup = $dt->format('F j, Y @ g:i A'); // ex: May 24, 2025 @ 4:37 PM
            } else {
                $formatted_pickup = 'Not scheduled';
            }

            $content .= "<pre>Pickup: $formatted_pickup\nCanceled: " . $item['pickup_canceled'] . "</pre>";

            if (!empty($item['scheduled_pickup']) && (int)$item['pickup_canceled'] !== 1) {
                $content .= "<div class='form-check mt-3'>
                    <input class='form-check-input' type='checkbox' name='cancel_pickup' id='cancel_pickup' value='1'>
                    <label class='form-check-label text-danger' for='cancel_pickup'>
                        Cancel Scheduled Pickup
                    </label>
                </div>
                <div class='form-group mt-2'>
                    <label for='pickup_canceled_reason'>Reason for Cancellation:</label>
                    <textarea name='pickup_canceled_reason' id='pickup_canceled_reason' class='form-control'></textarea>
                </div>";
            } elseif (!empty($item['pickup_canceled'])) {
                $content .= "<div class='alert alert-warning'>
                    Pickup was canceled on " . date('M j, Y g:i A', strtotime($item['pickup_canceled_at'])) . "<br>
                    <strong>Reason:</strong> " . htmlspecialchars($item['pickup_canceled_reason']) . "
                </div>";
            }
            
            $content .= "<button type='submit' class='btn btn-primary'>Update Item</button>";
            $content .= " <a href='?page=inventory' class='btn btn-secondary'>Cancel</a>";
            $content .= "</form>";
            
            // JavaScript to toggle fields
            $content .= "
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const consignorSelect = document.getElementById('consignor_id');
                const houseInventoryFields = document.getElementById('house_inventory_fields');
                
                consignorSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.text === 'House Inventory') {
                        houseInventoryFields.style.display = 'block';
                    } else {
                        houseInventoryFields.style.display = 'none';
                    }
                });
                
                // Initial toggle for title fields based on checkbox
                toggleTitleFields();
            });
            
            function toggleTitleFields() {
                const titleCheckbox = document.getElementById('is_titled');
                const titleFields = document.getElementById('title_fields');
                
                if (titleCheckbox.checked) {
                    titleFields.style.display = 'block';
                } else {
                    titleFields.style.display = 'none';
                }
            }
            </script>";
        } else {
            $content .= "<div class='alert alert-danger'>Item not found!</div>";
        }
        
        $conn->close();
    } else {
        $content .= "<div class='alert alert-danger'>No item ID provided!</div>";
    }
    break;
            
            
                case 'edit_consignor':
                    if (isset($_GET['consignor_id'])) {
                        $conn = connectDB();
                        $consignor_id = (int) $_GET['consignor_id'];
                
                        if ($consignor_id <= 0) {
                            $content .= "<div class='alert alert-danger'>Invalid consignor ID.</div>";
                            break;
                        }
                
                        $stmt = $conn->prepare("SELECT * FROM consignors WHERE id = ?");
                        $stmt->bind_param("i", $consignor_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $consignor = $result->fetch_assoc();
                
                        if ($consignor) {
                            ob_start();
                            ?>
                            <h2 class='mt-4'>Edit Consignor</h2>
                            <form method="post" action="?action=update_consignor" class="form" enctype="multipart/form-data">
                                <input type="hidden" name="id" value="<?= $consignor['id'] ?>">
                                <!-- form fields continue here... -->
  
                                <div class="text-center my-3">
    <a href='?action=generate_blank_agreement' target='_blank' class='btn btn-lg btn-warning mb-3 me-2'>
        Print Blank Agreement
    </a>
    <div class="form-group d-inline-block">
        <label for="agreement_file" class="form-label fw-bold d-block mb-2">
            Upload Signed Agreement:
        </label>
        <input type="file" name="agreement_file" id="agreement_file" class="form-control form-control-lg">
    </div>
</div>
                            
                            <form method="post" action="?action=update_consignor" class="form">
                                <input type="hidden" name="id" value="<?= $consignor['id'] ?>">
                                
                     
                
                                <div class="form-group">
    <label for="name">Full Name:</label>
    <input type="text" name="name" id="name" class="form-control" value="<?= htmlspecialchars($consignor['name'] ?? '') ?>">
</div>
<div class="form-group">
    <label for="email">Email Address:</label>
    <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($consignor['email'] ?? '') ?>">
</div>
<div class="form-group">
    <label for="phone">Phone Number:</label>
    <input type="text" name="phone" id="phone" class="form-control" value="<?= htmlspecialchars($consignor['phone'] ?? '') ?>">
</div>
<div class="form-group">
    <label for="address">Mailing Address:</label>
    <input type="text" name="address" id="address" class="form-control" value="<?= htmlspecialchars($consignor['address'] ?? '') ?>">
</div>
<div class="form-group">
    <label for="payment_details">Payment Details (optional):</label>
    <input type="text" name="payment_details" id="payment_details" class="form-control" value="<?= htmlspecialchars($consignor['payment_details'] ?? '') ?>">
</div>
<div class="form-group">
                                    <label for="preferred_payment_method">Preferred Payment Method:</label>
                                    <select name="payment_method" id="preferred_payment_method" class="form-control">
                                        <option value="">-- Select --</option>
                                        <option value="PayPal" <?= $consignor['payment_method'] == 'PayPal' ? 'selected' : '' ?>>PayPal</option>
                                        <option value="CashApp" <?= $consignor['payment_method'] == 'CashApp' ? 'selected' : '' ?>>CashApp</option>
                                        <option value="Venmo" <?= $consignor['payment_method'] == 'Venmo' ? 'selected' : '' ?>>Venmo</option>
                                        <option value="Check" <?= $consignor['payment_method'] == 'Check' ? 'selected' : '' ?>>Check</option>
                                    </select>
                                </div>
                                <div class='form-group'>
    <label for='paypal_email'>PayPal Email:</label>
    <input type='email' name='paypal_email' id='paypal_email' class='form-control' placeholder='e.g., name@example.com' value="<?= htmlspecialchars($consignor['paypal_email'] ?? '') ?>">
</div>
<div class='form-group'>
    <label for='cashapp_tag'>CashApp Tag:</label>
    <input type='text' name='cashapp_tag' id='cashapp_tag' class='form-control' placeholder='e.g., \$username' value="<?= htmlspecialchars($consignor['cashapp_tag'] ?? '') ?>">
</div>
<div class='form-group'>
    <label for='venmo_handle'>Venmo Handle:</label>
    <input type='text' name='venmo_handle' id='venmo_handle' class='form-control' placeholder='e.g., @username' value="<?= htmlspecialchars($consignor['venmo_handle'] ?? '') ?>">
</div>
<div class='form-group'>
    <label for='check_payable_to'>Make Checks Payable To:</label>
    <input type='text' name='check_payable_to' id='check_payable_to' class='form-control' placeholder='e.g., John Doe' value="<?= htmlspecialchars($consignor['check_payable_to'] ?? '') ?>">
</div>
  <div class='card mt-3'>
        <div class='card-header'><strong>Credit Card (for Abandonment Authorization)</strong></div>
        <div class='card-body'>
            <p class='text-muted'>
                This card will only be used if the consignor fails to respond to pickup or storage requests per the signed agreement.
            </p>
                
                                <div class="form-group">
                                    <label for="cc_number">Credit Card Number:</label>
                                    <input type="text" name="cc_number" id="cc_number" class="form-control" value="<?= htmlspecialchars($consignor['cc_number'] ?? '') ?>" placeholder="Enter full card number">
                                </div>
                
                                <div class="form-group">
                                    <label for="cc_expiry">Card Expiry (MM/YY):</label>
                                    <input type="text" name="cc_expiry" id="cc_expiry" class="form-control" value="<?= htmlspecialchars($consignor['cc_expiry'] ?? '') ?>" placeholder="MM/YY">
                                </div>
                
                                <div class="form-group">
                                    <label for="cc_cvv">CVV Code:</label>
                                    <input type="text" name="cc_cvv" id="cc_cvv" class="form-control" value="<?= htmlspecialchars($consignor['cc_cvv'] ?? '') ?>" placeholder="CVV">
                                </div>
                
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="agreement_on_file" name="agreement_on_file" value="1" <?= $consignor['agreement_on_file'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="agreement_on_file">Agreement Attached</label>
                                </div>

                                

<div class="form-group mt-3">
    <label for="license_file" class="form-label fw-bold d-block mb-2">Upload Driver’s License:</label>
    <input type="file" name="license_file" id="license_file" class="form-control-file" accept="image/*,.pdf">
    <small class="form-text text-muted">
        Valid, unexpired photo ID required for all consignors. PDF or image only.
        <br><br>
        <input type="checkbox" name="not_military_id" required>
        I confirm this is not a military ID or Common Access Card (CAC).
        <br>
        <strong>Federal law prohibits storing or copying U.S. military IDs.</strong> Please provide a state-issued ID or driver’s license instead.
    </small>
</div>

                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="abandonment_approved" name="abandonment_approved" value="1" <?= $consignor['abandonment_approved'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="abandonment_approved">Authorized to Charge for Abandonment</label>
                                </div>
                
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </form>
                            <?php
                            $content .= ob_get_clean();
                        } else {
                            $content .= "<p>Consignor not found.</p>";
                        }
                
                        $stmt->close();
                        $conn->close();
                    }
                    break;
                
                    case 'store_credit':
                        $conn = connectDB();
                        $content .= "<h2 class='mb-4'>Store Credit History</h2>";
            
                        $sql = "SELECT customer_name, amount, date_added FROM customer_credits ORDER BY date_added DESC";
                        $result = $conn->query($sql);
            
                        if ($result && $result->num_rows > 0) {
                            $content .= "<div class='table-responsive'>";
                            $content .= "<table class='table table-striped'>";
                            $content .= "<thead><tr>
                                            <th>Customer</th>
                                            <th>Credit Amount</th>
                                            <th>Date Issued</th>
                                        </tr></thead><tbody>";
            
                            while ($row = $result->fetch_assoc()) {
                                $content .= "<tr>
                                                <td>" . htmlspecialchars($row['customer_name']) . "</td>
                                                <td>$" . number_format($row['amount'], 2) . "</td>
                                                <td>" . date('m/d/Y', strtotime($row['date_added'])) . "</td>
                                            </tr>";
                            }
            
                            $content .= "</tbody></table></div>";
                        } else {
                            $content .= "<div class='alert alert-info'>No store credit entries found.</div>";
                        }
            
                        $conn->close();
                        break;
            
            
                
                    case 'consignor_details':
                        if (isset($_GET['consignor_id']) && is_numeric($_GET['consignor_id'])) {
                            $conn = connectDB();
                            $consignor_id = (int) $_GET['consignor_id'];
                    
                            if ($consignor_id <= 0) {
                                $content .= "<div class='alert alert-danger'>Invalid consignor ID.</div>";
                                break;
                            }
                    
                            // Add a no-print class to the Rental History button
                            $content .= "<div class='d-flex justify-content-start gap-2 mb-3 no-print'>
                            <a href='?page=rental_history&consignor_id={$consignor_id}' class='btn btn-secondary'>View Full Rental History</a>
                            <a href='#' onclick='window.print()' class='btn btn-warning'>Print Consignor Details</a>
                        </div>";
                       
                        
                            // You can continue with your SQL SELECT and the rest of your page here
                    
                
                    
                    // Get consignor info
                    $stmt = $conn->prepare("SELECT * FROM consignors WHERE id = ?");
                    $stmt->bind_param("i", $consignor_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $consignor = $result->fetch_assoc();
                    $stmt->close();
                    if (!$consignor) {
                        $content .= "<div class='alert alert-danger'>Consignor not found.</div>";
                        $conn->close();
                        break;
                    }
                

                    if (!empty($consignor['license_file'])) {
                        $ext = pathinfo($consignor['license_file'], PATHINFO_EXTENSION);
                        $url = htmlspecialchars($consignor['license_file'], ENT_QUOTES);
                    
                        $content .= "<div class='mb-3'><strong>Driver's License:</strong><br>";
                    
                        if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png'])) {
                            $content .= "<img src='{$url}' alt='Driver License' style='max-width:300px; border:1px solid #ccc;' class='mb-2'><br>";
                        }
                    
                        $content .= "<a href='{$url}' target='_blank' class='btn btn-outline-secondary btn-sm'>View Full License</a></div>";
                    }
                    
                    // Show Abandonment History
$sql = "SELECT a.*, i.description
FROM abandonments a
LEFT JOIN items i ON a.item_id = i.id
WHERE a.consignor_id = {$consignor_id}
ORDER BY a.abandonment_date DESC";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
$content .= "<h4 class='mt-4'>Abandonment History</h4>";
$content .= "<table class='table table-sm'>";
$content .= "<thead><tr><th>Item</th><th>Abandonment Date</th><th>Reason</th></tr></thead><tbody>";
while ($row = $result->fetch_assoc()) {
$content .= "<tr>";
$content .= "<td>" . htmlspecialchars($row['description']) . "</td>";
$content .= "<td>" . date('Y-m-d', strtotime($row['abandonment_date'])) . "</td>";
$content .= "<td>" . htmlspecialchars($row['reason']) . "</td>";
$content .= "</tr>";
}
$content .= "</tbody></table>";
} else {
    $content .= "<p class='text-center font-weight-bold'>No abandonments recorded for this consignor.</p>";
}
                    // Get consignor's items
                    $sql_items = "
    SELECT i.*, 
           (SELECT abandonment_date FROM abandonments a WHERE a.item_id = i.id LIMIT 1) as abandonment_date
    FROM items i
    WHERE (i.consignor_id = {$consignor_id} 
           OR EXISTS (SELECT 1 FROM abandonments a WHERE a.item_id = i.id AND a.consignor_id = {$consignor_id}))
    ORDER BY i.status DESC, i.description ASC
";
$items_result = $conn->query($sql_items);
                
                    // Tally totals
                    $total_items = 0;
                    $total_value = 0;
                    $items = [];
                
                    while ($item = $items_result->fetch_assoc()) {
                        $items[] = $item;
                        $total_items++;
                        $total_value += $item['asking_price'];
                    }
                
// Get sales/consignment history
$stmt_sales = $conn->prepare("
    SELECT 
        s.id AS sale_id,            -- ✅ Needed for View Receipt
        s.sale_date,
        s.sale_price,
        s.commission_amount,
        s.sales_tax,
        s.buyer_name,
        s.payment_method,
        s.consignor_paid,
        s.license_file,
        i.description,
        i.id AS item_id
    FROM sales s
    INNER JOIN items i ON s.item_id = i.id
    WHERE i.consignor_id = ?
    ORDER BY s.sale_date DESC
");

if (!$stmt_sales) {
    die("Prepare failed: " . $conn->error);
}
if (!$stmt_sales) {
    die("SQL Error: " . $conn->error);
}
$stmt_sales->bind_param("i", $consignor_id);
$stmt_sales->execute();
$sales_result = $stmt_sales->get_result();
                
                    ob_start();
                    ?>
                    <div class="d-flex justify-content-between align-items-center">
                    <style>
@media print {
    .no-print, .no-print * {
        display: none !important;
    }
}
</style>
                        <h2 class='mt-4'>Consignor Details</h2>
                        
                        
                        
                    </div>
                
                    <?php if ($consignor): ?>
                        <p><strong>ID:</strong> <?= htmlspecialchars($consignor['id']) ?></p>
                        <p><strong>Name:</strong> <?= htmlspecialchars($consignor['name']) ?></p>
                        <p><strong>Email:</strong> <?= htmlspecialchars($consignor['email']) ?> 
                            <a href="mailto:<?= htmlspecialchars($consignor['email']) ?>" class="btn btn-sm btn-link">?? Email</a>
                        </p>
                        <p><strong>Phone:</strong> <?= htmlspecialchars($consignor['phone']) ?></p>
                        <p><strong>Address:</strong> <?= nl2br(htmlspecialchars($consignor['address'])) ?></p>
                        <p><strong>Payment Method:</strong> <?= htmlspecialchars($consignor['payment_method']) ?></p>
                        <p><strong>Paypal:</strong> <?= htmlspecialchars($consignor['paypal_email'] ?? '') ?></p>
<p><strong>CashApp:</strong> <?= htmlspecialchars($consignor['cashapp_tag'] ?? '') ?></p>
<p><strong>Venmo:</strong> <?= htmlspecialchars($consignor['venmo_handle'] ?? '') ?></p>
<p><strong>Make Check payable to:</strong> <?= htmlspecialchars($consignor['check_payable_to'] ?? '') ?></p>
                        <p><strong>Card Number:</strong> <?= htmlspecialchars($consignor['cc_number']) ?> (Exp: <?= htmlspecialchars($consignor['cc_expiry']) ?>)</p>
                        <p><strong>CVV:</strong> <?= htmlspecialchars($consignor['cc_cvv']) ?></p>
                        <?php if (!empty($consignor['agreement_file'])): ?>
    <?php
        $file_path = htmlspecialchars($consignor['agreement_file']);
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        // If it's a PDF, let it open in the browser. Otherwise, force download.
        $force_download = ($ext !== 'pdf');
        $label = "Download " . strtoupper($ext);
    ?>
    <p><strong>Signed Agreement:</strong> 
        <a href="<?= $file_path ?>" 
           target="_blank" 
           <?= $force_download ? 'download' : '' ?> 
           class="btn btn-sm btn-outline-primary"><?= $label ?></a>
    </p>
<?php endif; ?>
<p><strong>Authorized for Abandonment Charge:</strong> <?= $consignor['abandonment_approved'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?></p>
                
                        <h4 class="mt-4">Summary</h4>
                        <ul>
                            <li><strong>Total Items on Lot:</strong> <?= $total_items ?></li>
                            <li><strong>Total Asking Value:</strong> $<?= number_format($total_value, 2) ?></li>
                        </ul>
                
                        <h4 class="mt-4">Items</h4>
<table class="table table-bordered table-striped">
    <thead>
        <tr>
            <th>Description</th>
            <th>Make/Model</th>
            <th>Category</th>
            <th>Status</th>
            <th>Asking Price</th>
            <th class="no-print">Agreement</th> <!-- New column -->
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['description']) ?></td>
                <td><?= htmlspecialchars($item['make_model']) ?></td>
                <td><?= htmlspecialchars($item['category']) ?></td>
                <td><?= htmlspecialchars($item['status']) ?></td>
                <td>$<?= number_format($item['asking_price'], 2) ?></td>
                <td class="no-print">
                    <?php if (!empty($item['agreement_signed']) && $item['agreement_signed'] == 1): ?>
                        <span class="text-success">✅ Signed</span>
                        <?php if (!empty($item['agreement_file'])): ?>
                            <a href="uploads/agreements/<?= htmlspecialchars($item['agreement_file']) ?>" 
                               target="_blank" class="btn btn-sm btn-info">View</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a href="?action=generate_agreement&item_id=<?= $item['id'] ?>" 
                           target="_blank" class="btn btn-sm btn-primary">Generate</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
                
                        <h4 class="mt-4">Consignment Sales History</h4>
<?php if ($sales_result->num_rows > 0): ?>
    <table class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>Date</th>
                <th>Item</th>
                <th>Sale Price</th>
                <th>Commission</th>
                <th>Profit</th>
                <th>Paid to Consignor</th>
                <th>Buyer</th>
                <th>Payment</th>
                <th>Receipt</th> <!-- Updated label -->
            </tr>
        </thead>
        <tbody>
            <?php while ($sale = $sales_result->fetch_assoc()): ?>
                <?php $profit = $sale['sale_price'] - $sale['commission_amount'] - $sale['sales_tax']; ?>
                <tr>
                    <td><?= htmlspecialchars($sale['sale_date']) ?></td>
                    <td><?= htmlspecialchars($sale['description']) ?></td>
                    <td>$<?= number_format($sale['sale_price'], 2) ?></td>
                    <td>$<?= number_format($sale['commission_amount'], 2) ?></td>
                    <td>$<?= number_format($profit, 2) ?></td>
                    <td><?= $sale['consignor_paid'] ? '<span class="text-success">Paid</span>' : '<span class="text-danger">Unpaid</span>' ?></td>
                    <td><?= htmlspecialchars($sale['buyer_name']) ?></td>
                    <td><?= htmlspecialchars($sale['payment_method']) ?></td>
                    <td>
                        <a href="?page=generate_invoice&sale_id=<?= $sale['sale_id'] ?>" class="btn btn-sm btn-secondary" target="_blank">View Receipt</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No sales history found for this consignor.</p>
<?php endif; ?>

</tbody>
                                    <?php while ($sale = $sales_result->fetch_assoc()): ?>
                                        <?php
                                            $profit = $sale['sale_price'] - $sale['commission_amount'] - $sale['sales_tax'];
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($sale['sale_date']) ?></td>
                                            <td><?= htmlspecialchars($sale['description']) ?></td>
                                                <td>$<?= number_format($sale['sale_price'], 2) ?></td>
                                                <td>$<?= number_format($sale['commission_amount'], 2) ?></td>
                                                <td>$<?= number_format($profit, 2) ?></td>
                                                <td>
                                                    <?= $sale['consignor_paid'] ? '<span class="text-success">Paid</span>' : '<span class="text-danger">Unpaid</span>' ?>
                                                </td>
                                                <td><?= htmlspecialchars($sale['buyer_name']) ?></td>
                                                <td><?= htmlspecialchars($sale['payment_method']) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
    <p>No sales history found for this consignor.</p>
<?php endif; ?>
<?php

                 
                        $stmt_sales->close();
                        $conn->close();
            
                        $content .= ob_get_clean();
                    } else {
                        $content .= "<div class='alert alert-danger'>Missing or invalid consignor ID.</div>";
                    }
            break;
        }
    
    // Handle actions
/**
 * Records a sale in the database
 * 
 * @param int $item_id The ID of the item being sold
 * @param float $sale_price The sale price of the item
 * @param string $buyer_name Name of the buyer
 * @param string $buyer_contact Contact information for the buyer
 * @param string $payment_method Method of payment used
 * @param float $credit_applied Any credit applied to the sale
 * @return int|bool The ID of the newly created sale or false on failure
 */
function recordSale($item_id, $sale_price, $buyer_name, $buyer_contact, $payment_method, $credit_applied = 0) {
    $conn = connectDB();
    
    // Get item details to calculate commission
    $stmt = $conn->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    
    if (!$item) {
        error_log("recordSale: Item not found for ID $item_id");
        return false;
    }
    
    $consignor_id = $item['consignor_id'];
    
    // Calculate commission (25% standard, minimum $50)
    $commission_rate = 0.25; // 25%
    $commission_amount = $sale_price * $commission_rate;
    if ($sale_price < 200 && $commission_amount < 50) {
        $commission_amount = 50;
    }
    
    // Calculate sales tax (assume 8.25% but you can adjust this)
    $tax_rate = 0.0825; // 8.25%
    $sales_tax = $sale_price * $tax_rate;
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Insert into sales table
        $stmt = $conn->prepare("
            INSERT INTO sales (
                item_id, sale_date, sale_price, buyer_name, buyer_contact, 
                payment_method, commission_amount, sales_tax, consignor_paid, credit_applied
            ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, 0, ?)
        ");
        
        $stmt->bind_param(
            "idsssddd", 
            $item_id, 
            $sale_price, 
            $buyer_name, 
            $buyer_contact, 
            $payment_method, 
            $commission_amount, 
            $sales_tax,
            $credit_applied
        );
        
        $success = $stmt->execute();
        if (!$success) {
            error_log("recordSale: Failed to insert sale record: " . $stmt->error);
            throw new Exception("Failed to insert sale record");
        }
        
        $sale_id = $conn->insert_id;
        $stmt->close();
        
        // Update item status
        $stmt = $conn->prepare("UPDATE items SET status = 'sold', sold_date = NOW() WHERE id = ?");
        $stmt->bind_param("i", $item_id);
        $success = $stmt->execute();
        if (!$success) {
            error_log("recordSale: Failed to update item status: " . $stmt->error);
            throw new Exception("Failed to update item status");
        }
        $stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        error_log("recordSale: Sale recorded successfully. Sale ID: $sale_id");
        return $sale_id;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        error_log("recordSale Exception: " . $e->getMessage());
        return false;
    } finally {
        $conn->close();
    }
}
    
// =======================[ ACTION: generate_agreement ]=======================
if ($action === 'generate_agreement' && isset($_GET['item_id'])) {
    $item_id = (int) $_GET['item_id'];
    generateConsignmentAgreement($item_id); // should output PDF directly and exit
    $content .= "<div class='alert alert-danger'>Error generating agreement!</div>";
}
    if ($action == 'email_consignor' && isset($_GET['item_id']) && isset($_GET['type'])) {
        $item_id = $_GET['item_id'];
        $type = $_GET['type'];
        
        $conn = connectDB();
        
        // Get item and consignor details
        $sql = "SELECT i.*, c.name, c.email 
                FROM items i
                JOIN consignors c ON i.consignor_id = c.id
                WHERE i.id = ?";
                
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            
            $content .= "<h2 class='mt-4'>Email Consignor</h2>";
            $content .= "<div class='alert alert-info'>Preparing email to {$data['name']} ({$data['email']})...</div>";
            
            // Email templates
            $subject = "";
            $message = "";
            
            if ($type == '30day') {
                $subject = "Your consigned item at BACK2WORK EQUIPMENT - 30 Day Update";
                $message = "Dear {$data['name']},\n\n";
                $message .= "Your item ({$data['description']}) has been on consignment with us for 30 days.\n\n";
                $message .= "To increase its chances of selling, we recommend one of the following options:\n";
                $message .= "1. Reduce the asking price from \${$data['asking_price']} to \$" . number_format($data['asking_price'] * 0.9, 2) . "\n";
                $message .= "2. Allow us to feature it in our promotional materials\n";
                $message .= "3. Consider adding services or upgrades to make it more appealing\n\n";
                $message .= "Please let us know which option you prefer by replying to this email or calling us at (555) 123-4567.\n\n";
                $message .= "Thank you for choosing BACK2WORK EQUIPMENT for your consignment needs.\n\n";
                $message .= "Sincerely,\nBACK2WORK EQUIPMENT";
            } else if ($type == '60day') {
                $subject = "IMPORTANT: Your consigned item at BACK2WORK EQUIPMENT - 60 Day Notice";
                $message = "Dear {$data['name']},\n\n";
                $message .= "Your item ({$data['description']}) has been on consignment with us for 60 days.\n\n";
                $message .= "According to our agreement, we now need a decision on next steps. Your options are:\n\n";
                $message .= "1. REDUCE PRICE: Lower the asking price to \$" . number_format($data['asking_price'] * 0.8, 2) . " (20% reduction)\n";
                $message .= "2. EXTEND PERIOD: Keep the current price for another 30 days\n";
                $message .= "3. PICK UP ITEM: Arrange to collect your item within 7 days\n\n";
                $message .= "If we don't hear from you within 7 days, we may need to implement option #1 automatically.\n\n";
                $message .= "Please contact us at (555) 123-4567 to discuss your preferred option.\n\n";
                $message .= "Thank you for your prompt attention to this matter.\n\n";
                $message .= "Sincerely,\nBACK2WORK EQUIPMENT";
            } else if ($type == '120day') {
                $subject = "FINAL NOTICE: Your consigned item at BACK2WORK EQUIPMENT - 120 Day Update";
                $message = "Dear {$data['name']},\n\n";
                $message .= "Your item ({$data['description']}) has now been on consignment with us for 120 days.\n\n";
                $message .= "At this stage, we require final action. Your options are:\n\n";
                $message .= "1. FINAL PRICE REDUCTION: Lower the asking price to \$" . number_format($data['asking_price'] * 0.7, 2) . " (30% reduction)\n";
                $message .= "2. PICK UP ITEM: Arrange to retrieve your item within 7 days\n";
                $message .= "3. AUTHORIZE US TO LIQUIDATE: Allow us to sell the item at best available offer\n\n";
                $message .= "If we do not receive your response within 7 days, we may proceed with option #3 according to the terms of your consignment agreement.\n\n";
                $message .= "Please contact us immediately at (555) 123-4567 to confirm how you would like to proceed.\n\n";
                $message .= "Thank you for consigning with BACK2WORK EQUIPMENT.\n\n";
                $message .= "Sincerely,\nBACK2WORK EQUIPMENT";
            }
            
            $content .= "<div class='card'>";
            $content .= "<div class='card-header'><strong>Email Preview</strong></div>";
            $content .= "<div class='card-body'>";
            $content .= "<p><strong>To:</strong> {$data['email']}</p>";
            $content .= "<p><strong>Subject:</strong> {$subject}</p>";
            $content .= "<p><strong>Message:</strong></p>";
            $content .= "<pre>" . htmlspecialchars($message) . "</pre>";
            $content .= "</div></div>";
            
            // In a real app, you would actually send the email here
            $content .= "<div class='alert alert-success mt-3'>Email sent successfully!</div>";
            $content .= "<p><a href='?page=aging_inventory' class='btn btn-secondary'>Return to Aging Inventory</a></p>";
        } else {
            $content .= "<div class='alert alert-danger'>Item not found!</div>";
        }
        
        $conn->close();
    }

    
    if ($action === 'process_rental' && isset($_GET['item_id'])) {
    $item_id = (int) $_GET['item_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $conn = connectDB();

        // Ensure tax columns exist
        $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'tax_rate'");
        if ($result->num_rows == 0) {
            $conn->query("ALTER TABLE rentals ADD COLUMN tax_rate DECIMAL(6,4) DEFAULT 0.0825");
        }
        $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'tax_amount'");
        if ($result->num_rows == 0) {
            $conn->query("ALTER TABLE rentals ADD COLUMN tax_amount DECIMAL(10,2) DEFAULT 0.00");
        }

        // Gather form data
        $renter_name = $_POST['renter_name'];
        $renter_contact = $_POST['renter_contact'];
        $renter_phone = $_POST['renter_phone'] ?? '';
        $renter_email = $_POST['renter_email'] ?? '';
        $renter_address = $_POST['renter_address'] ?? '';
        $renter_id_number = $_POST['renter_id_number'] ?? '';
        $license_state = $_POST['license_state'] ?? '';

        // Delivery details
        $delivery_phone = $_POST['delivery_phone'] ?? '';
        $delivery_address = $_POST['delivery_address'] ?? '';
        $mileage = isset($_POST['mileage']) ? floatval($_POST['mileage']) : 0.0;

        // Dates
        $scheduled_pickup = $_POST['scheduled_pickup'];
        $scheduled_return = $_POST['scheduled_return'];
        $rental_start = substr($scheduled_pickup, 0, 10);
        $rental_end = substr($scheduled_return, 0, 10);

        // Calculate days
        $start_date = new DateTime($rental_start);
        $end_date = new DateTime($rental_end);
        $days_diff = $end_date->diff($start_date)->days + 1;

        // Options
        $pickup_required = isset($_POST['pickup_required']) ? 1 : 0;
        $delivery_required = isset($_POST['delivery_required']) ? 1 : 0;

        // Financials
        $daily_rate = floatval($_POST['daily_rate']);
        $deposit_amount = floatval($_POST['deposit_amount']);
        $tax_rate = 0.0825;
        $subtotal = $daily_rate * $days_diff;
        $delivery_fee = ($delivery_required && $mileage <= 30 && $subtotal >= 75) ? 50.00 : 0.00;
        $taxable_amount = $subtotal + $delivery_fee;
        $tax_amount = $taxable_amount * $tax_rate;
        $total_amount = $taxable_amount + $tax_amount;

        // File upload
        $license_file = null;
        if (isset($_FILES['drivers_license']) && $_FILES['drivers_license']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . "/uploads/licenses/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $filename = time() . "_" . basename($_FILES['drivers_license']['name']);
            $target_path = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['drivers_license']['tmp_name'], $target_path)) {
                $license_file = "uploads/licenses/" . $filename;
            }
        }

        // Prepare insert
        $sql = "INSERT INTO rentals (
            item_id, renter_name, renter_phone, renter_email, renter_address, renter_contact,
            renter_id_number, license_state, license_file, daily_rate, rental_start, rental_end,
            deposit, total_amount, pickup_required, delivery_required, delivery_phone, delivery_address,
            mileage, scheduled_pickup, scheduled_return, tax_rate, tax_amount, status, number_of_days
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $content .= "<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>";
        } else {
            $status = 'active';

            $stmt->bind_param("issssssssdssdddiiisssddsi",
    $item_id, $renter_name, $renter_phone, $renter_email, $renter_address, $renter_contact,
    $renter_id_number, $license_state, $license_file, $daily_rate, $rental_start, $rental_end,
    $deposit_amount, $total_amount, $pickup_required, $delivery_required, $delivery_phone, $delivery_address,
    $mileage, $scheduled_pickup, $scheduled_return, $tax_rate, $tax_amount, $status, $days_diff
);


            if ($stmt->execute()) {
                $rental_id = $conn->insert_id;

                // Update item
                $update = $conn->prepare("UPDATE items SET status = 'rented' WHERE id = ?");
                $update->bind_param("i", $item_id);
                $update->execute();
                $update->close();

                // Fetch item description
                $item_stmt = $conn->prepare("SELECT description FROM items WHERE id = ?");
                $item_stmt->bind_param("i", $item_id);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item_description = "Equipment";
                if ($item_row = $item_result->fetch_assoc()) {
                    $item_description = $item_row['description'];
                }
                $item_stmt->close();

                // Success message
                $content .= "<div class='alert alert-success'>
                    <h4>Rental Created Successfully!</h4>
                    <p>Rental #" . str_pad($rental_id, 5, '0', STR_PAD_LEFT) . " has been created for " . htmlspecialchars($renter_name) . ".</p>
                    <p><strong>Equipment:</strong> " . htmlspecialchars($item_description) . "</p>
                    <p><strong>Period:</strong> " . date('m/d/Y', strtotime($rental_start)) . " to " . date('m/d/Y', strtotime($rental_end)) . " (" . $days_diff . " days)</p>
                    <p><strong>Daily Rate:</strong> $" . number_format($daily_rate, 2) . "</p>
                    <p><strong>Subtotal:</strong> $" . number_format($subtotal, 2) . "</p>";
                if ($delivery_fee > 0) {
                    $content .= "<p><strong>Delivery Fee:</strong> $" . number_format($delivery_fee, 2) . "</p>";
                }
                $content .= "<p><strong>Sales Tax (8.25%):</strong> $" . number_format($tax_amount, 2) . "</p>
                    <p><strong>Total Due:</strong> $" . number_format($total_amount, 2) . "</p>";
                if ($deposit_amount > 0) {
                    $content .= "<p><strong>Security Deposit:</strong> $" . number_format($deposit_amount, 2) . "</p>";
                }
                $content .= "<div class='mt-3'>
                    <a href='?action=generate_rental_invoice&rental_id={$rental_id}' class='btn btn-info'>View Rental Invoice</a>
                    <a href='?page=rentals' class='btn btn-secondary'>Return to Rentals</a>
                </div></div>";
            } else {
                $content .= "<div class='alert alert-danger'>Error creating rental: " . $stmt->error . "</div>";
            }

            $stmt->close();
        }

        $conn->close();
    }
}


// Handler for print_return_receipt as both a page and an action
if (($action === 'print_return_receipt' || (isset($_GET['page']) && $_GET['page'] === 'print_return_receipt')) && isset($_GET['rental_id']) && is_numeric($_GET['rental_id'])) {
    // Enable error reporting for troubleshooting
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    
    $rental_id = (int) $_GET['rental_id'];
    $conn = connectDB();
    
    // Output debug info
    echo "<!-- 
    DEBUG START
    Handling print_return_receipt for rental_id: {$rental_id}
    Called as: " . (($action === 'print_return_receipt') ? 'action' : 'page') . "
    -->";
    
    // Check if deposit columns exist
    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'deposit'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN deposit DECIMAL(10,2) DEFAULT 0.00");
    }

    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'deposit_returned'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN deposit_returned DECIMAL(10,2) DEFAULT 0.00");
    }
    
    // Check if full_refund column exists
    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'full_refund'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN full_refund TINYINT(1) DEFAULT 0");
    }
    
    // Get rental data with all the info we need
    $stmt = $conn->prepare("SELECT r.*, i.description, i.make_model, c.name AS consignor_name
                           FROM rentals r
                           LEFT JOIN items i ON r.item_id = i.id
                           LEFT JOIN consignors c ON i.consignor_id = c.id
                           WHERE r.id = ?");
    
    if (!$stmt) {
        echo "<div style='padding: 20px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;'>
              <h3>Error preparing statement</h3>
              <p>Error: " . $conn->error . "</p>
              </div>";
        $conn->close();
        exit;
    }
    
    $stmt->bind_param("i", $rental_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $rental = $result->fetch_assoc();
        $stmt->close(); // Close the statement now that we're done with it
        
        // Debug output for rental data
        echo "<!-- 
        RENTAL DATA:
        ID: " . $rental_id . "
        Status: " . ($rental['status'] ?? 'Not set') . "
        Total Amount: " . ($rental['total_amount'] ?? 'Not set') . "
        Rental Fee: " . ($rental['rental_fee'] ?? 'Not set') . "
        -->";
        
        // Check for refunds from the refunds table
        $refund_sql = "SELECT * FROM refunds WHERE type = 'rental' AND reference_id = ? ORDER BY date_issued DESC LIMIT 1";
        $refund_stmt = $conn->prepare($refund_sql);
        
        if ($refund_stmt) {
            $refund_stmt->bind_param("i", $rental_id);
            $refund_stmt->execute();
            $refund_result = $refund_stmt->get_result();
            $external_refund = ($refund_result && $refund_result->num_rows > 0) ? $refund_result->fetch_assoc() : false;
            $refund_stmt->close();
            
            // Debug external refund
            if ($external_refund) {
                echo "<!-- 
                REFUND FOUND:
                Amount: " . $external_refund['amount'] . "
                Date: " . $external_refund['date_issued'] . "
                -->";
            } else {
                echo "<!-- No external refund found -->";
            }
        } else {
            $external_refund = false;
            echo "<!-- Failed to prepare refund statement: " . $conn->error . " -->";
        }
        
        $pickup = !empty($rental['rental_start']) ? date('m/d/Y h:i A', strtotime($rental['rental_start'])) : '[Not set]';
        $return = !empty($rental['rental_end']) ? date('m/d/Y h:i A', strtotime($rental['rental_end'])) : '[Not set]';
        $returned_on = !empty($rental['returned_on']) ? date('m/d/Y h:i A', strtotime($rental['returned_on'])) : 'Not returned';
        
        // Get proper rental fee - check for various field names
        $rental_fee = 0;
        if (!empty($rental['rental_fee'])) {
            $rental_fee = floatval($rental['rental_fee']);
        } else if (!empty($rental['total_amount'])) {
            $rental_fee = floatval($rental['total_amount']);
        }
        
        // Add more styling to make the receipt look professional
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Rental Return Receipt #" . str_pad($rental_id, 5, '0', STR_PAD_LEFT) . "</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 40px; }
                h2 { text-align: center; margin-bottom: 10px; }
                .section { margin-bottom: 15px; }
                .financial { background-color: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin: 20px 0; }
                .row { display: flex; justify-content: space-between; margin-bottom: 8px; }
                .total-row { font-weight: bold; border-top: 1px solid #000; padding-top: 10px; margin-top: 10px; }
                .refund-box { background-color: #f0fff0; border: 1px solid #009900; padding: 15px; margin-top: 15px; }
                .negative { color: #009900; }
                hr { margin: 20px 0; }
                .signature-box { border: 1px solid #000; padding: 30px; min-height: 50px; margin-top: 30px; }
                .full-refund { background-color: #DFF2BF; color: #4F8A10; padding: 10px; border: 1px solid #4F8A10; margin: 15px 0; }
            </style>
        </head>
        <body>";
        
        echo "<h2>Rental Return Receipt</h2>";
        echo "<p style='text-align: center;'>Back2Work Equipment</p><hr>";
        
        echo "<div class='section'>";
        echo "<p><strong>Renter:</strong> " . htmlspecialchars($rental['renter_name']) . "</p>";
        
        if (!empty($rental['renter_contact'])) {
            echo "<p><strong>Contact:</strong> " . htmlspecialchars($rental['renter_contact']) . "</p>";
        }
        echo "</div>";
        
        echo "<div class='section'>";
        echo "<p><strong>Item:</strong> " . htmlspecialchars($rental['description']) . " - " . htmlspecialchars($rental['make_model']) . "</p>";
        if (!empty($rental['consignor_name'])) {
            echo "<p><strong>Consignor:</strong> " . htmlspecialchars($rental['consignor_name']) . "</p>";
        }
        echo "</div>";
        
        echo "<div class='section'>";
        echo "<p><strong>Rental Period:</strong> $pickup to $return</p>";
        echo "<p><strong>Returned On:</strong> $returned_on</p>";
        echo "</div>";
        
        // Check if this is a full refund
        $full_refund = isset($rental['full_refund']) && $rental['full_refund'] == 1;
        
        // Financial section
        echo "<div class='financial'>";
        echo "<h3>Financial Information</h3>";
        
        // Full Refund notice
        if ($full_refund) {
            echo "<div class='full-refund'><strong>✓ FULL RENTAL REFUND ISSUED</strong><br>";
            echo "This rental has been fully refunded.";
            if (!empty($rental['refund_reason'])) {
                echo "<br>Reason: " . htmlspecialchars($rental['refund_reason']);
            }
            echo "</div>";
        }
        
        // Rental fees and charges
        $additional_charges = isset($rental['additional_charges']) ? floatval($rental['additional_charges']) : 0;
        
        if ($rental_fee > 0) {
            echo "<div class='row'><span>Rental Fee:</span><span>$" . number_format($rental_fee, 2) . "</span></div>";
            
            // If full refund, show the rental fee as refunded
            if ($full_refund) {
                echo "<div class='row'><span>Rental Fee Refunded:</span><span class='negative'>-$" . number_format($rental_fee, 2) . "</span></div>";
            }
        }
        
        if ($additional_charges > 0) {
            echo "<div class='row'><span>Additional Charges:</span><span>$" . number_format($additional_charges, 2) . "</span></div>";
            
            // If full refund, show additional charges as refunded too
            if ($full_refund) {
                echo "<div class='row'><span>Additional Charges Refunded:</span><span class='negative'>-$" . number_format($additional_charges, 2) . "</span></div>";
            }
            
            if (!empty($rental['additional_notes'])) {
                echo "<p><small>Reason: " . htmlspecialchars($rental['additional_notes']) . "</small></p>";
            }
        }
        
        // Simple refund (if any)
        $simple_refund = isset($rental['refund_amount']) && $rental['refund_amount'] > 0 ? floatval($rental['refund_amount']) : 0;
        if ($simple_refund > 0 && !$full_refund) { // Don't show partial refund if full refund is issued
            echo "<div class='row'><span>Adjustment Refund:</span><span class='negative'>-$" . number_format($simple_refund, 2) . "</span></div>";
            
            if (!empty($rental['refund_reason'])) {
                echo "<p><small>Reason: " . htmlspecialchars($rental['refund_reason']) . "</small></p>";
            }
        }
        
        // Calculate total without deposit
        $total_without_deposit = $full_refund ? 0 : ($rental_fee + $additional_charges - $simple_refund);
        echo "<div class='row total-row'><span>Rental Total:</span><span>$" . number_format($total_without_deposit, 2) . "</span></div>";
        
        echo "</div>"; // End financial section
        
        // Deposit section
        // First check if we have deposit_amount field (older schema) or deposit field (newer schema)
        $deposit = 0;
        if (isset($rental['deposit_amount']) && $rental['deposit_amount'] > 0) {
            $deposit = floatval($rental['deposit_amount']);
        } else if (isset($rental['deposit']) && $rental['deposit'] > 0) {
            $deposit = floatval($rental['deposit']);
        }
        
        // For deposit returned, check both possible fields
        $deposit_returned = 0;
        if (isset($rental['deposit_refunded']) && $rental['deposit_refunded'] > 0) {
            $deposit_returned = floatval($rental['deposit_refunded']);
        } else if (isset($rental['deposit_returned']) && $rental['deposit_returned'] > 0) {
            $deposit_returned = floatval($rental['deposit_returned']);
        }
        
        if ($deposit > 0) {
            echo "<div class='financial'>";
            echo "<h3>Security Deposit</h3>";
            echo "<div class='row'><span>Deposit Collected:</span><span>$" . number_format($deposit, 2) . "</span></div>";
            
            if ($deposit_returned > 0 || $external_refund || $full_refund) {
                // If full refund, assume deposit is fully refunded unless otherwise specified
                $refund_amount = $full_refund ? $deposit : 
                                 ($external_refund ? floatval($external_refund['amount']) : $deposit_returned);
                
                echo "<div class='row'><span>Deposit Refunded:</span><span class='negative'>-$" . number_format($refund_amount, 2) . "</span></div>";
                
                if ($external_refund && !$full_refund) {
                    echo "<p><small>Refunded on " . date('m/d/Y', strtotime($external_refund['date_issued'])) . 
                         " by " . htmlspecialchars($external_refund['issued_by']) . "</small></p>";
                    
                    if (!empty($external_refund['reason'])) {
                        echo "<p><small>Reason: " . htmlspecialchars($external_refund['reason']) . "</small></p>";
                    }
                    
                    // Show charges if any
                    $has_charges = false;
                    $total_charges = 0;
                    
                    foreach (['damage_fee', 'delivery_fee', 'fuel_surcharge', 'cleaning_fee', 'late_fee', 'misc_fee'] as $fee) {
                        if (isset($external_refund[$fee]) && $external_refund[$fee] > 0) {
                            if (!$has_charges) {
                                echo "<div style='margin-top: 10px;'><strong>Charges Applied:</strong></div>";
                                $has_charges = true;
                            }
                            $fee_label = ucwords(str_replace('_', ' ', $fee));
                            echo "<div class='row'><span>- {$fee_label}:</span><span>$" . number_format($external_refund[$fee], 2) . "</span></div>";
                            $total_charges += $external_refund[$fee];
                        }
                    }
                    
                    if ($has_charges) {
                        echo "<div class='row' style='margin-top: 5px;'><span>Total Charges:</span><span>$" . number_format($total_charges, 2) . "</span></div>";
                        
                        // Show calculation
                        echo "<p><small>Calculation: $" . number_format($deposit, 2) . " (deposit) - $" . 
                             number_format($total_charges, 2) . " (charges) = $" . number_format($refund_amount, 2) . " (refund)</small></p>";
                    }
                }
            } else {
                echo "<div class='row'><span>Deposit Refunded:</span><span>$0.00</span></div>";
                echo "<p><small>Note: Security deposit has not been refunded yet.</small></p>";
            }
            
            echo "</div>"; // End deposit section
        }
        
        // Full Summary section - Show the grand total calculation
        echo "<div class='financial'>";
        echo "<h3>Payment Summary</h3>";
        
        $grand_total = $total_without_deposit;
        
        // Only show this if we aren't doing a full refund
        if (!$full_refund) {
            echo "<div class='row'><span>Rental Charges Total:</span><span>$" . number_format($total_without_deposit, 2) . "</span></div>";
            
            // If deposit was not fully returned, show remainder as a fee
            if ($deposit > 0 && $deposit_returned < $deposit && !$full_refund) {
                $deposit_kept = $deposit - $deposit_returned;
                echo "<div class='row'><span>Deposit Amount Kept:</span><span>$" . number_format($deposit_kept, 2) . "</span></div>";
                $grand_total += $deposit_kept;
            }
            
            echo "<div class='row total-row'><span>Grand Total Due:</span><span>$" . number_format($grand_total, 2) . "</span></div>";
        } else {
            echo "<div class='full-refund'><strong>✓ ALL FEES REFUNDED</strong><br>";
            echo "No payment is due for this rental.</div>";
        }
        
        echo "</div>";
        
        // Inspection section
        if (!empty($rental['inspection_notes']) || isset($rental['inspection_passed'])) {
            echo "<div class='section'>";
            echo "<h3>Inspection Details</h3>";
            
            if (isset($rental['inspection_passed'])) {
                echo "<p><strong>Inspection Result:</strong> " . ($rental['inspection_passed'] ? 'PASSED' : 'FAILED') . "</p>";
            }
            
            if (!empty($rental['inspection_notes'])) {
                echo "<p><strong>Inspection Notes:</strong><br>" . nl2br(htmlspecialchars($rental['inspection_notes'])) . "</p>";
            }
            
            if (!empty($rental['inspection_file'])) {
                $ext = pathinfo($rental['inspection_file'], PATHINFO_EXTENSION);
                echo "<p><strong>Inspection File:</strong> ";
                if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    echo "<br><img src='" . htmlspecialchars($rental['inspection_file']) . "' style='max-width: 300px; max-height: 200px;'>";
                } else {
                    echo "<a href='" . htmlspecialchars($rental['inspection_file']) . "' target='_blank'>View Uploaded File</a>";
                }
                echo "</p>";
            }
            
            echo "</div>";
        }
        
        // Signature section
        echo "<hr>";
        echo "<div class='section'>";
        echo "<p><strong>Agreement Terms:</strong><br>";
        echo "Renter acknowledges that the equipment was in good working condition at the time of rental. Renter assumes full responsibility for any loss, damage, or repairs required. The card on file may be charged for repair or replacement of damaged or missing equipment.<br><br>";
        echo "Renter understands that failing to return equipment or failing to make the item available for pickup by the return date may result in an additional rental fee.<br><br>";
        echo "Back2Work Equipment is not responsible for any injuries, damages, or losses arising from the use of rented equipment.";
        echo "</p>";
        echo "</div>";
        
        echo "<div class='section'>";
        echo "<p><strong>Authorized By (Staff Signature):</strong></p>";
        echo "<div class='signature-box'></div>";
        echo "<p><strong>Date:</strong> " . date('m/d/Y') . "</p>";
        echo "</div>";
        
        echo "<!-- End of receipt content -->";
        
        echo "<script>window.onload = function() { window.print(); };</script>";
        echo "</body></html>";
    } else {
        echo "<div style='text-align:center; margin-top: 50px;'><h3>Rental not found</h3><p>No rental found with ID: " . $rental_id . "</p></div>";
        
        // Only try to close if we have a valid statement
        if ($stmt) {
            $stmt->close();
        }
    }
    
    $conn->close();
    exit;
}

    if ($action === 'export_rental_history_csv' && isset($_GET['consignor_id'])) {
        $consignor_id = (int) $_GET['consignor_id'];
        $conn = connectDB();
    
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="rental_history_consignor_' . $consignor_id . '.csv"');
    
        require_once('MyPDF.php'); // Use your custom reusable TCPDF class
        
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Rental ID', 'Item', 'Rental Period',
            'Renter', 'Contact', 'Phone', 'Email', 'Address',
            'ID / License #', 'License State', 'License File',
            'Daily Rate', 'Total', 'Status'
        ]);
        
    
        $sql = "
            SELECT r.*, i.description, i.make_model 
            FROM rentals r
            JOIN items i ON r.item_id = i.id
            WHERE i.consignor_id = {$consignor_id}
            ORDER BY r.rental_start DESC
        ";
        $result = $conn->query($sql);
    
        while ($row = $result->fetch_assoc()) {
            $rental_id = "R-" . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
            $status = $row['status'] === 'completed' ? 'Completed' : 'Active';
            $period = $row['rental_start'] . " to " . $row['rental_end'];
    
            fputcsv($output, [
                $rental_id,
                $row['description'] . ' (' . $row['make_model'] . ')',
                $period,
                $row['renter_name'] . ' - ' . $row['renter_contact'],
                $row['daily_rate'],
                $row['total_amount'],
                $status
            ]);
        }
    
        fclose($output);
        $conn->close();
        exit;
    }
    
    
   if ($action === 'end_rental' && isset($_GET['rental_id']) && is_numeric($_GET['rental_id'])) {
    $rental_id = (int) $_GET['rental_id'];
    $conn = connectDB();

    $stmt = $conn->prepare("SELECT r.*, i.description FROM rentals r LEFT JOIN items i ON r.item_id = i.id WHERE r.id = ?");
    if (!$stmt) {
        $content = "<div class='alert alert-danger'>Failed to prepare statement: " . $conn->error . "</div>";
    } else {
        $stmt->bind_param("i", $rental_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if (!$result) {
            $content .= "<div class='alert alert-danger'>Query failed: " . $stmt->error . "</div>";
            $stmt->close();
        } else if ($result->num_rows === 0) {
            $content = "<div class='alert alert-warning'>No rental found with ID: {$rental_id}</div>";
            $stmt->close();
        } else {
            $rental = $result->fetch_assoc();
            $stmt->close();

            if ($rental['status'] === 'completed') {
                $content = "<div class='alert alert-warning'>This rental has already been completed.</div>";
            } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Process the form submission
                // Get form data
                $inspection_passed = isset($_POST['inspection_passed']) ? 1 : 0;
                $inspection_notes = trim($_POST['inspection_notes'] ?? '');
                $returned_on = date('Y-m-d H:i:s');
                $make_available = isset($_POST['make_available']) ? 1 : 0;
                
                // Get refund information
                $refund_amount = isset($_POST['refund_amount']) ? floatval($_POST['refund_amount']) : 0;
                $refund_reason = trim($_POST['refund_reason'] ?? '');
                $refund_date = $refund_amount > 0 ? date('Y-m-d H:i:s') : null;
                
                // Check if full refund is requested
                $full_refund = isset($_POST['full_refund']) ? 1 : 0;

                $inspection_file = null;
                if (isset($_FILES['inspection_file']) && $_FILES['inspection_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . "/uploads/inspection/";
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $filename = time() . "_" . basename($_FILES['inspection_file']['name']);
                    $target_path = $upload_dir . $filename;
                    if (move_uploaded_file($_FILES['inspection_file']['tmp_name'], $target_path)) {
                        $inspection_file = "uploads/inspection/" . $filename;
                    }
                }

                // Update database
                $update_sql = "UPDATE rentals SET 
                    status = 'completed', 
                    inspection_passed = ?, 
                    inspection_notes = ?, 
                    returned_on = ?";
                
                $params = [$inspection_passed, $inspection_notes, $returned_on];
                $types = "iss"; // Integer, String, String
                
                // Add inspection_file if we have one
                if ($inspection_file !== null) {
                    $update_sql .= ", inspection_file = ?";
                    $params[] = $inspection_file;
                    $types .= "s"; // String
                }
                
                // Add refund fields if amount > 0 or full refund
                if ($refund_amount > 0 || $full_refund) {
                    // If full refund, use refund reason or default
                    $refund_text = $full_refund ? 
                        ($refund_reason ? $refund_reason : "Full rental refund issued") : 
                        $refund_reason;
                    
                    $update_sql .= ", refund_amount = ?, refund_reason = ?, refund_date = ?, full_refund = ?";
                    $params[] = $full_refund ? ($rental['rental_fee'] ?? $rental['total_amount'] ?? 0) : $refund_amount;
                    $params[] = $refund_text;
                    $params[] = $refund_date ?? date('Y-m-d H:i:s');
                    $params[] = $full_refund;
                    $types .= "dssi"; // Double, String, String, Integer
                }
                
                $update_sql .= " WHERE id = ?";
                $params[] = $rental_id;
                $types .= "i"; // Integer
                
                $update_stmt = $conn->prepare($update_sql);
                
                if (!$update_stmt) {
                    $content .= "<div class='alert alert-danger'>Update prepare failed: " . $conn->error . "</div>";
                } else {
                    // Process full refund if requested
                    if ($full_refund) {
                        $rental_fee = isset($rental['rental_fee']) ? floatval($rental['rental_fee']) : 
                                     (isset($rental['total_amount']) ? floatval($rental['total_amount']) : 0);
                        $deposit = isset($rental['deposit']) ? floatval($rental['deposit']) : 0;
                        $total_to_refund = $rental_fee + $deposit;

                        $issued_by = $_SESSION['username'] ?? 'admin';

                        // Create a record in the refunds table
                        $refund_stmt = $conn->prepare("INSERT INTO refunds 
                            (type, reference_id, amount, reason, date_issued, issued_by, returned_to_inventory, issued_credit) 
                            VALUES ('rental', ?, ?, ?, NOW(), ?, 1, 0)");
                        
                        $reason = $refund_reason ? $refund_reason : "Full refund granted (rental + deposit)";
                        $refund_stmt->bind_param("idss", $rental_id, $total_to_refund, $reason, $issued_by);
                        $refund_stmt->execute();
                        $refund_stmt->close();

                        // Mark deposit as returned in the rentals table
                        if ($deposit > 0) {
                            $conn->query("UPDATE rentals SET deposit_returned = {$deposit} WHERE id = {$rental_id}");
                        }
                        
                        // Mark the item as available again
                        $conn->query("UPDATE items SET status = 'available', rental_authorized = 1 WHERE id = {$rental['item_id']}");
                        
                        // Force make_available to true for full refunds
                        $make_available = 1;
                    }

                    // Dynamically bind parameters
                    $update_stmt_bind_param = function($stmt, $types, $params) {
                        $bind_names[] = $types;
                        for ($i = 0; $i < count($params); $i++) {
                            $bind_name = 'bind' . $i;
                            $$bind_name = $params[$i];
                            $bind_names[] = &$$bind_name;
                        }
                        return call_user_func_array(array($stmt, 'bind_param'), $bind_names);
                    };
                    
                    if (!$update_stmt_bind_param($update_stmt, $types, $params)) {
                        $content .= "<div class='alert alert-danger'>Binding parameters failed: " . $update_stmt->error . "</div>";
                    } else {
                        if (!$update_stmt->execute()) {
                            $content .= "<div class='alert alert-danger'>Update execution failed: " . $update_stmt->error . "</div>";
                        } else {
                            // Handle make_available if needed
                            if ($make_available) {
                                $item_stmt = $conn->prepare("UPDATE items SET rental_authorized = 1, status = 'available' WHERE id = ?");
                                if (!$item_stmt) {
                                    $content .= "<div class='alert alert-warning'>Could not prepare item update: " . $conn->error . "</div>";
                                } else {
                                    $item_stmt->bind_param("i", $rental['item_id']);
                                    if (!$item_stmt->execute()) {
                                        $content .= "<div class='alert alert-warning'>Failed to make item available: " . $item_stmt->error . "</div>";
                                    }
                                    $item_stmt->close();
                                }
                            }
                            
                            // Check if there's a security deposit to refund
                            $show_refund_link = false;
                            if (isset($rental['deposit']) && $rental['deposit'] > 0 && !$full_refund) {
                                $show_refund_link = true;
                            }
                            
                            // Show success message with options
                            $content = "<div class='alert alert-success'>
                                <h4>Rental #" . str_pad($rental_id, 5, '0', STR_PAD_LEFT) . " has been successfully completed!</h4>";
                                
                            // Show full refund message if applicable
                            if ($full_refund) {
                                $content = "<div class='alert alert-success'>
                                    <i class='fas fa-check-circle'></i> <strong>Full refund processed!</strong> 
                                    Amount: $" . number_format($total_to_refund, 2) . "
                                </div>";
                            }
                            
                            $content .= "<div class='mt-3'>
                                    <a href='?page=print_return_receipt&rental_id={$rental_id}' class='btn btn-info' target='_blank'>Print Return Receipt</a>";
                            
                            if ($show_refund_link) {
                                $content .= " <a href='?action=issue_refund&type=rental&id={$rental_id}' class='btn btn-warning'>Issue Deposit Refund</a>";
                            }
                            
                            $content .= " <a href='?page=rentals' class='btn btn-secondary'>Return to Rentals</a>
                                </div>
                            </div>";

                            if ($make_available) {
                                $content .= "<p class='text-success'><i class='fas fa-check-circle'></i> Item was marked as available for future rentals.</p>";
                            }
                        }
                    }
                    $update_stmt->close();
                }
            } else {
                // Display the rental completion form
                $content = "<div class='container mt-5'>";
                $content .= "<div class='card'>";
                $content .= "<div class='card-header'><strong>Return & Inspection for Rental #R-" . str_pad($rental_id, 5, '0', STR_PAD_LEFT) . "</strong></div>";
                $content .= "<div class='card-body'>";
                $content .= "<p><strong>Equipment:</strong> " . htmlspecialchars($rental['description']) . "</p>";
                
                // Get proper rental fee - check for various field names
                $rental_fee = 0;
                if (!empty($rental['rental_fee'])) {
                    $rental_fee = floatval($rental['rental_fee']);
                } else if (!empty($rental['total_amount'])) {
                    $rental_fee = floatval($rental['total_amount']);
                }
                
                // Display rental fee information
                $content .= "<p><strong>Rental Fee:</strong> $" . number_format($rental_fee, 2) . "</p>";
                
                // Display deposit information if available
                if (isset($rental['deposit']) && $rental['deposit'] > 0) {
                    $content .= "<div class='alert alert-info'>
                        <strong>Security Deposit:</strong> $" . number_format($rental['deposit'], 2) . "
                        <br><small>You can process a deposit refund after completing this return.</small>
                    </div>";
                }
                
                $content .= "<form method='post' action='?action=end_rental&rental_id={$rental_id}' enctype='multipart/form-data'>";
                
                // Inspection section
                $content .= "<div class='card mb-3'>";
                $content .= "<div class='card-header'><strong>Inspection Details</strong></div>";
                $content .= "<div class='card-body'>";
                $content .= "<div class='form-group'><label>Inspection Notes</label><textarea name='inspection_notes' class='form-control' required></textarea></div>";
                $content .= "<div class='form-group'><label>Upload Inspection Photo or File</label><input type='file' name='inspection_file' class='form-control-file' accept='image/*,.pdf'></div>";
                $content .= "<div class='form-check mb-2'><input type='checkbox' name='inspection_passed' id='inspection_passed' class='form-check-input'> <label for='inspection_passed' class='form-check-label'>Inspection Passed</label></div>";
                $content .= "<div class='form-check mb-3'><input type='checkbox' name='make_available' id='make_available' class='form-check-input'> <label for='make_available' class='form-check-label'>Make item available for next rental</label></div>";
                $content .= "</div></div>";
                
                // Simple refund section
                $content .= "<div class='card mb-3'>";
                $content .= "<div class='card-header'><strong>Refund Options</strong></div>";
                $content .= "<div class='card-body'>";
                $content .= "<div class='alert alert-warning'>
                    <i class='fas fa-info-circle'></i> For deposit refunds or complex refunds, complete the return process first, then use the 'Issue Deposit Refund' button.
                </div>";
                
                // Minor refund fields
                $content .= "<div id='minor_refund_section'>";
                $content .= "<div class='form-group'>";
                $content .= "<label for='refund_amount'>Minor Refund Amount ($):</label>";
                $content .= "<input type='number' step='0.01' class='form-control' id='refund_amount' name='refund_amount' value='0.00'>";
                $content .= "</div>";
                $content .= "</div>";
                
                // Refund reason (used for both minor and full refunds)
                $content .= "<div class='form-group'>";
                $content .= "<label for='refund_reason'>Refund Reason:</label>";
                $content .= "<textarea name='refund_reason' id='refund_reason' class='form-control' rows='2'></textarea>";
                $content .= "</div>";
                
                // Full refund checkbox
                $content .= "<div class='form-check mb-3'>";
                $content .= "<input type='checkbox' class='form-check-input' id='full_refund' name='full_refund'> ";
                $content .= "<label for='full_refund' class='form-check-label text-danger'><strong>Full Refund</strong> (Rental + Deposit)</label>";
                $content .= "</div>";
                
                // Add JavaScript to handle checkbox behavior
                $content .= "
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var fullRefundCheckbox = document.getElementById('full_refund');
                    var minorRefundSection = document.getElementById('minor_refund_section');
                    var refundAmountInput = document.getElementById('refund_amount');
                    var makeAvailableCheckbox = document.getElementById('make_available');
                    
                    fullRefundCheckbox.addEventListener('change', function() {
                        if(this.checked) {
                            minorRefundSection.style.display = 'none';
                            refundAmountInput.value = '0.00';
                            makeAvailableCheckbox.checked = true;
                        } else {
                            minorRefundSection.style.display = 'block';
                        }
                    });
                });
                </script>";
                
                $content .= "</div></div>";
                
                // Submit button
                $content .= "<button type='submit' class='btn btn-success btn-lg'>Complete Rental</button>";

                $content .= "</form>";
                
                $content .= "</div></div></div>";
            }
        }
    }
    
    $conn->close();
    
}

if ($action === 'print_return_receipt' && isset($_GET['rental_id']) && is_numeric($_GET['rental_id'])) {
    // Enable error reporting for troubleshooting
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    
    $rental_id = (int) $_GET['rental_id'];
    $conn = connectDB();
    
    // Check if deposit columns exist
    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'deposit'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN deposit DECIMAL(10,2) DEFAULT 0.00");
    }

    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'deposit_returned'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN deposit_returned DECIMAL(10,2) DEFAULT 0.00");
    }
    
    // Get rental data
    $stmt = $conn->prepare("SELECT r.*, i.description, i.make_model FROM rentals r LEFT JOIN items i ON r.item_id = i.id WHERE r.id = ?");
    $stmt->bind_param("i", $rental_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $rental = $result->fetch_assoc();
        $stmt->close();

        // Default values for deposit columns
        if (!isset($rental['deposit'])) $rental['deposit'] = 0;
        if (!isset($rental['deposit_returned'])) $rental['deposit_returned'] = 0;
        
        // Check for refunds from the refunds table
        $refund_sql = "SELECT * FROM refunds WHERE type = 'rental' AND reference_id = ? ORDER BY date_issued DESC LIMIT 1";
        $refund_stmt = $conn->prepare($refund_sql);
        $refund_stmt->bind_param("i", $rental_id);
        $refund_stmt->execute();
        $refund_result = $refund_stmt->get_result();
        $external_refund = ($refund_result && $refund_result->num_rows > 0) ? $refund_result->fetch_assoc() : false;
        $refund_stmt->close();
        
        
        // Output the receipt content
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Rental Return Receipt #" . str_pad($rental_id, 5, '0', STR_PAD_LEFT) . "</title>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 40px; }
                h2, h4 { text-align: center; margin-bottom: 10px; }
                .section { margin-bottom: 20px; }
                .signature-box { border: 1px solid #000; padding: 30px; min-height: 50px; font-style: italic; font-family: cursive; }
                .preview-img { max-width: 100%; height: auto; margin-top: 10px; }
                .label { font-weight: bold; }
                hr { margin: 30px 0; }
                .financial { padding: 15px; border: 1px solid #ddd; background-color: #f9f9f9; margin-bottom: 20px; }
                .amount { text-align: right; }
                .total-row { font-weight: bold; border-top: 1px solid #000; margin-top: 10px; padding-top: 10px; }
                .negative { color: #009900; }
                .refund-box { border: 1px solid #009900; background-color: #f0fff0; padding: 15px; margin-top: 15px; }
                .row { display: flex; justify-content: space-between; margin-bottom: 8px; }
            </style>
        </head>
        <body>";
        
        echo "<h2>Rental Return Receipt</h2>";
        echo "<h4>Back2Work Equipment</h4><hr>";

        echo "<div class='section'><span class='label'>Renter:</span> " . htmlspecialchars($rental['renter_name']) . "<br>";
        echo "<span class='label'>Contact:</span> " . htmlspecialchars($rental['renter_contact']) . "</div>";

        echo "<div class='section'><span class='label'>Equipment:</span> " . htmlspecialchars($rental['description']) . "<br>";
        echo "<span class='label'>Make/Model:</span> " . htmlspecialchars($rental['make_model']) . "</div>";

        if (!empty($rental['scheduled_pickup']) && !empty($rental['scheduled_return'])) {
            $pickup = date('m/d/Y h:i A', strtotime($rental['scheduled_pickup']));
            $return = date('m/d/Y h:i A', strtotime($rental['scheduled_return']));
            $rental_days = (new DateTime($rental['scheduled_pickup']))->diff(new DateTime($rental['scheduled_return']))->days + 1;
            echo "<div class='section'><span class='label'>Scheduled Period:</span> {$pickup} to {$return} ({$rental_days} days)</div>";
        } else {
            echo "<div class='section'><span class='label'>Scheduled Period:</span> [Not Provided]</div>";
        }

        if (!empty($rental['scheduled_delivery'])) {
            echo "<div class='section'><span class='label'>Scheduled Delivery:</span> " . date('m/d/Y h:i A', strtotime($rental['scheduled_delivery'])) . "</div>";
        }

        if (!empty($rental['returned_on'])) {
            echo "<div class='section'><span class='label'>Returned On:</span> " . date('m/d/Y h:i A', strtotime($rental['returned_on'])) . "</div>";
        }

        echo "<div class='section'><span class='label'>Inspection Notes:</span><br>" . nl2br(htmlspecialchars($rental['inspection_notes'])) . "</div>";
        echo "<div class='section'><span class='label'>Inspection Result:</span> " . ($rental['inspection_passed'] ? 'PASSED' : 'FAILED') . "</div>";

        if (!empty($rental['inspection_file'])) {
            $ext = pathinfo($rental['inspection_file'], PATHINFO_EXTENSION);
            echo "<div class='section'><span class='label'>Inspection File:</span><br>";
            if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                echo "<img src='" . htmlspecialchars($rental['inspection_file']) . "' class='preview-img'>";
            } else {
                echo "<a href='" . htmlspecialchars($rental['inspection_file']) . "' target='_blank'>View Uploaded File</a>";
            }
            echo "</div>";
        }

        // Inside the print_return_receipt or print_rental_start_receipt handler
// Add disclosures section after the item details

// Add disclosures section
if (!empty($rental['known_issues']) || !empty($rental['wear_description']) || !empty($rental['hours_used']) || !empty($rental['maintenance_history'])) {
    echo "<div class='section' style='border: 1px solid #333; padding: 15px; margin-top: 20px;'>";
    echo "<h4 style='border-bottom: 1px solid #333; padding-bottom: 5px;'>Equipment Condition & Disclosures</h4>";
    
    if (!empty($rental['hours_used'])) {
        echo "<p><strong>Hours Used:</strong> {$rental['hours_used']} hours</p>";
    }
    
    if (!empty($rental['condition_desc'])) {
        echo "<p><strong>General Condition:</strong> " . nl2br(htmlspecialchars($rental['condition_desc'])) . "</p>";
    }
    
    if (!empty($rental['known_issues'])) {
        echo "<p><strong>Known Issues:</strong> " . nl2br(htmlspecialchars($rental['known_issues'])) . "</p>";
    }
    
    if (!empty($rental['wear_description'])) {
        echo "<p><strong>Signs of Wear:</strong> " . nl2br(htmlspecialchars($rental['wear_description'])) . "</p>";
    }
    
    if (!empty($rental['maintenance_history'])) {
        echo "<p><strong>Maintenance History:</strong> " . nl2br(htmlspecialchars($rental['maintenance_history'])) . "</p>";
    }
    
    echo "<p style='margin-top: 15px; font-style: italic;'>Renter acknowledges that they have received and reviewed all disclosures about this equipment's condition and accepts the equipment in its current condition.</p>";
    
    echo "</div>"; // End disclosures div
}

        // Financial summary section
        echo "<hr><div class='section'><strong>Financial Summary</strong></div>";
        echo "<div class='financial'>";

        // Update the return receipt to include tax information in the financial section
// Add this inside the financial section of your print_return_receipt function around line 5979
echo "<div class='row'><span>Subtotal:</span><span>$" . number_format($rental_subtotal, 2) . "</span></div>";
echo "<div class='row'><span>Sales Tax (" . number_format($tax_rate * 100, 2) . "%):</span><span>$" . number_format($tax_amount, 2) . "</span></div>";
        
        // Rental fee
        $rental_fee = isset($rental['rental_fee']) ? floatval($rental['rental_fee']) : 0;
        if ($rental_fee > 0) {
            echo "<div class='row'>";
            echo "<span class='label'>Rental Fee:</span>";
            echo "<span class='amount'>$" . number_format($rental_fee, 2) . "</span>";
            echo "</div>";
        }
        
        // Deposit
        $deposit = isset($rental['deposit']) ? floatval($rental['deposit']) : 0;
        $deposit_returned = isset($rental['deposit_returned']) ? floatval($rental['deposit_returned']) : 0;
        
        if ($deposit > 0) {
            echo "<div class='row'>";
            echo "<span class='label'>Security Deposit:</span>";
            echo "<span class='amount'>$" . number_format($deposit, 2) . "</span>";
            echo "</div>";
            
            // Display deposit refunded status
            if ($deposit_returned > 0) {
                echo "<div class='row'>";
                echo "<span class='label'>Deposit Refunded:</span>";
                echo "<span class='amount negative'>-$" . number_format($deposit_returned, 2) . "</span>";
                echo "</div>";
            }
        }

        // Additional charges if any
        $additional_charges = isset($rental['additional_charges']) ? floatval($rental['additional_charges']) : 0;
        if ($additional_charges > 0) {
            echo "<div class='row'>";
            echo "<span class='label'>Additional Charges:</span>";
            echo "<span class='amount'>$" . number_format($additional_charges, 2) . "</span>";
            echo "</div>";
            
            if (!empty($rental['additional_notes'])) {
                echo "<div class='section'>";
                echo "<span class='label'>Reason for Additional Charges:</span><br>";
                echo htmlspecialchars($rental['additional_notes']);
                echo "</div>";
            }
        }
        
        // Simple refund from rental completion (if any)
        $simple_refund = isset($rental['refund_amount']) ? floatval($rental['refund_amount']) : 0;
        if ($simple_refund > 0) {
            echo "<div class='row'>";
            echo "<span class='label'>Adjustment Refund:</span>";
            echo "<span class='amount negative'>-$" . number_format($simple_refund, 2) . "</span>";
            echo "</div>";
            
            if (!empty($rental['refund_reason'])) {
                echo "<div class='section'>";
                echo "<span class='label'>Refund Reason:</span><br>";
                echo htmlspecialchars($rental['refund_reason']);
                echo "</div>";
            }
        }
        
        // Calculate initial total (without detailed refund info)
        $total_charges = $rental_fee + $additional_charges;
        $total_refunds = $simple_refund;
        $initial_total = $total_charges - $total_refunds;
        
        // Show rental total
        echo "<div class='row total-row'>";
        echo "<span class='label'>Rental Total:</span>";
        echo "<span class='amount'>$" . number_format($initial_total, 2) . "</span>";
        echo "</div>";
        
        echo "</div>"; // Close financial div
        
        // External refund section from the refunds table (if exists)
        if ($external_refund) {
            echo "<div class='refund-box'>";
            echo "<h4 style='color: #009900; margin-top: 0;'>Deposit Refund</h4>";
            
            echo "<div class='row'>";
            echo "<span class='label'>Refund Amount:</span>";
            echo "<span class='amount'>$" . number_format($external_refund['amount'], 2) . "</span>";
            echo "</div>";
            
            if (!empty($external_refund['reason'])) {
                echo "<div class='section'>";
                echo "<span class='label'>Refund Reason:</span><br>";
                echo nl2br(htmlspecialchars($external_refund['reason']));
                echo "</div>";
            }
            
            echo "<div class='section'>";
            echo "<span class='label'>Issued By:</span> " . htmlspecialchars($external_refund['issued_by']) . " on " . 
                 date('m/d/Y', strtotime($external_refund['date_issued']));
            echo "</div>";
            
            // Display detailed charges if any
            $has_charges = false;
            $total_charges = 0;
            
            foreach (['damage_fee', 'delivery_fee', 'fuel_surcharge', 'cleaning_fee', 'late_fee', 'misc_fee'] as $fee) {
                if (isset($external_refund[$fee]) && $external_refund[$fee] > 0) {
                    if (!$has_charges) {
                        echo "<div class='section'><span class='label'>Charges Applied:</span></div>";
                        echo "<div class='financial' style='background-color: #fff; margin-bottom: 10px;'>";
                        $has_charges = true;
                    }
                    $fee_label = ucwords(str_replace('_', ' ', $fee));
                    echo "<div class='row'>";
                    echo "<span>" . $fee_label . ":</span>";
                    echo "<span class='amount'>$" . number_format($external_refund[$fee], 2) . "</span>";
                    echo "</div>";
                    $total_charges += $external_refund[$fee];
                }
            }
            
            if ($has_charges) {
                echo "<div class='row total-row'>";
                echo "<span class='label'>Total Charges:</span>";
                echo "<span class='amount'>$" . number_format($total_charges, 2) . "</span>";
                echo "</div>";
                echo "</div>"; // Close inner financial div
                
                // Show deposit calculation
                echo "<div class='section'>";
                echo "<span class='label'>Deposit Calculation:</span><br>";
                echo "Original Deposit: $" . number_format($deposit, 2) . "<br>";
                echo "Less Charges: -$" . number_format($total_charges, 2) . "<br>";
                echo "Refund Amount: $" . number_format($external_refund['amount'], 2);
                echo "</div>";
            }
            
            echo "</div>"; // Close refund-box
        } else if ($deposit > 0 && $deposit_returned == 0) {
            // Show notice about deposit if it hasn't been refunded
            echo "<div class='section'>";
            echo "<div class='alert' style='border: 1px solid #ffc107; background-color: #fff3cd; padding: 10px;'>";
            echo "<strong>Note:</strong> Security deposit of $" . number_format($deposit, 2) . " has not yet been refunded.";
            echo "</div>";
            echo "</div>";
        }

        echo "<hr><div class='section'><strong>Agreement Terms</strong><br>";
        echo "Renter acknowledges that the equipment was in good working condition at the time of rental. Renter assumes full responsibility for any loss, damage, or repairs required. The card on file may be charged for repair or replacement of damaged or missing equipment.<br><br>";
        echo "Renter understands that failing to return equipment or failing to make the item available for pickup by the return date may result in an additional rental fee.<br><br>";
        echo "Back2Work Equipment is not responsible for any injuries, damages, or losses arising from the use of rented equipment.";
        echo "</div>";

        echo "<hr><div class='section'><span class='label'>Authorized By (Staff Signature):</span><div class='signature-box'></div></div>";
        echo "<div class='section'><span class='label'>Date:</span> " . date('m/d/Y') . "</div>";

        echo "<script>window.onload = function() { window.print(); };</script>";
        echo "</body></html>";
} else {
    if ($stmt) $stmt->close();
    $content = "<div class='alert alert-danger'>Rental not found.</div>"; // Use $content instead of echo
}

$conn->close();
    
}
    
    if ($action == 'export_completed_rentals_csv') {
        $conn = connectDB();
    
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
        $pageNum = isset($_GET['p']) ? (int) $_GET['p'] : 1;
        $offset = ($pageNum - 1) * $limit;
    
        $sql = "
            SELECT r.id, i.description, i.make_model, r.renter_name, r.renter_contact, r.returned_on,
                   r.inspection_passed, r.inspection_notes
            FROM rentals r
            JOIN items i ON r.item_id = i.id
            WHERE r.status = 'completed'
            ORDER BY r.returned_on DESC
            LIMIT $limit OFFSET $offset
        ";
    
        $result = $conn->query($sql);
    
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="completed_rentals.csv"');
    
        $output = fopen('php://output', 'w');
    
        // Header row
        fputcsv($output, ['Rental ID', 'Description', 'Make/Model', 'Renter Name', 'Contact', 'Returned On', 'Inspection', 'Notes']);
    
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                "R-" . str_pad($row['id'], 5, "0", STR_PAD_LEFT),
                $row['description'],
                $row['make_model'],
                $row['renter_name'],
                $row['renter_contact'],
                $row['returned_on'],
                $row['inspection_passed'] ? 'Passed' : 'Failed',
                $row['inspection_notes']
            ]);
        }
    
        fclose($output);
        exit;
    }
    
    
   // Update the generate_rental_invoice function to include tax information
if ($action == 'generate_rental_invoice' && isset($_GET['rental_id']) && is_numeric($_GET['rental_id'])) {
    $rental_id = (int) $_GET['rental_id'];
    $conn = connectDB();
    
    // Ensure tax columns exist
    ensureTaxColumnsExist($conn);
    
    $stmt = $conn->prepare("SELECT r.*, i.description, i.make_model FROM rentals r LEFT JOIN items i ON r.item_id = i.id WHERE r.id = ?");
    $stmt->bind_param("i", $rental_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();
        
        // Get tax information, use defaults if columns don't exist yet
        $tax_rate = isset($data['tax_rate']) ? floatval($data['tax_rate']) : 0.0825;
        $tax_amount = isset($data['tax_amount']) ? floatval($data['tax_amount']) : 0;
        
        $content .= "<h2 class='mt-4'>Rental Invoice</h2>";
        $content .= "<div style='max-width: 800px; margin: 0 auto; padding: 20px; border: 1px solid #ddd;'>";
        $content .= "<div style='text-align: center; margin-bottom: 20px;'>";
        $content .= "<h1>BACK2WORK EQUIPMENT</h1><h3>Rental Agreement & Invoice</h3>";
        $content .= "</div>";

        $content .= "<p><strong>Invoice #:</strong> R-" . str_pad($rental_id, 5, '0', STR_PAD_LEFT) . "</p>";
        $content .= "<p><strong>Date:</strong> " . date('m/d/Y h:i A') . "</p>";

        $content .= "<p><strong>Rented To:</strong><br>" . htmlspecialchars($data['renter_name']) . "<br>" . htmlspecialchars($data['renter_contact']) . "</p>";

        $content .= "<p><strong>Equipment:</strong> " . htmlspecialchars($data['description']) . "<br><small>Make/Model: " . htmlspecialchars($data['make_model']) . "</small></p>";

        if (!empty($data['rental_start']) && $data['rental_start'] !== '0000-00-00') {
            $start_date = date('m/d/Y', strtotime($data['rental_start']));
            $end_date = (!empty($data['rental_end']) && $data['rental_end'] !== '0000-00-00') ? date('m/d/Y', strtotime($data['rental_end'])) : '[Missing End Date]';
            $days = (new DateTime($data['rental_start']))->diff(new DateTime($data['rental_end']))->days + 1;
            $content .= "<p><strong>Rental Period:</strong> {$start_date} to {$end_date} ({$days} days)</p>";
        } else {
            $content .= "<p><strong>Rental Period:</strong> [Invalid or Missing Start Date]</p>";
            $days = 0;
        }

        // Display other info (pickup, delivery, etc.)
        if (!empty($data['scheduled_pickup'])) {
            $content .= "<p><strong>Scheduled Pickup:</strong> " . date('m/d/Y h:i A', strtotime($data['scheduled_pickup'])) . "</p>";
        }

        if (!empty($data['scheduled_delivery'])) {
            $content .= "<p><strong>Scheduled Delivery:</strong> " . date('m/d/Y h:i A', strtotime($data['scheduled_delivery'])) . "</p>";
        }

        if (!empty($data['delivery_address'])) {
            $content .= "<p><strong>Delivery Address:</strong> " . htmlspecialchars($data['delivery_address']) . "</p>";
        }

        if (!is_null($data['mileage'])) {
            $content .= "<p><strong>Mileage:</strong> " . floatval($data['mileage']) . " miles</p>";
        }

        // Financial calculations
        $subtotal = isset($data['rental_fee']) ? floatval($data['rental_fee']) : ($days * floatval($data['daily_rate']));
        $deliveryFee = isset($data['delivery_fee']) ? floatval($data['delivery_fee']) : 0;
        
        // Use stored tax_amount if available, otherwise calculate
        if ($tax_amount == 0) {
            $tax = ($subtotal + $deliveryFee) * $tax_rate;
        } else {
            $tax = $tax_amount;
        }
        
        $total = $subtotal + $deliveryFee + $tax;

        $content .= "<hr><table style='width: 100%; border-collapse: collapse;'>";
        $content .= "<tr><th style='text-align: left;'>Description</th><th style='text-align: right;'>Amount</th></tr>";
        $content .= "<tr><td>Equipment Rental - {$days} day(s) @ $" . number_format($data['daily_rate'], 2) . "/day</td><td style='text-align: right;'>$" . number_format($subtotal, 2) . "</td></tr>";

        if ($deliveryFee > 0) {
            $content .= "<tr><td>Flat-Rate Delivery Fee</td><td style='text-align: right;'>$" . number_format($deliveryFee, 2) . "</td></tr>";
        }

        // Always show tax line
        $content .= "<tr><td>Sales Tax (" . number_format($tax_rate * 100, 2) . "%)</td><td style='text-align: right;'>$" . number_format($tax, 2) . "</td></tr>";
        $content .= "<tr><td><strong>Total</strong></td><td style='text-align: right; font-weight: bold;'>$" . number_format($total, 2) . "</td></tr>";
        $content .= "</table><hr>";

        // Rest of the content remains the same...
        $content .= "<div style='margin-top: 20px;'><strong>Agreement Terms</strong><br>";
        $content .= "Renter acknowledges that the equipment is in good working condition at the time of rental. Renter assumes full responsibility for any loss, damage, or repairs required. The card on file will be charged for any damage or loss.";
        $content .= "Renter understands that failing to return equipment or failing to make the item available for pickup by the return date may result in an additional rental fee.<br><br>";
        $content .= "Back2Work Equipment is not responsible for any injuries, damages, or losses arising from the use of rented equipment.";
        $content .= "</div>";

        $content .= "<p><strong>Payment Method:</strong> __________________________</p>";
        $content .= "<p>Thank you for your business!</p>";

        // Receipt button
        $content .= "<div style='margin-top: 20px;'><a href='?action=print_return_receipt&rental_id={$rental_id}' class='btn btn-outline-primary' target='_blank'>View Receipt</a></div>";

        $content .= "</div>";
        $content .= "<p class='mt-3'><a href='?page=rentals' class='btn btn-secondary'>Return to Rentals</a></p>";
    } else {
        $content .= "<div class='alert alert-danger'>Rental not found!</div>";
    }
    $stmt->close();
    $conn->close();
}


    
         if ($action == 'mark_paid' && isset($_GET['sale_id'])) {
        $sale_id = $_GET['sale_id'];
        
        $conn = connectDB();
        
        // Update sale payment status
        $sql = "UPDATE sales SET consignor_paid = 1 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $sale_id);
        
        if ($stmt->execute()) {
            $content .= "<div class='alert alert-success'>Consignor payment marked as completed!</div>";
            $content .= "<p><a href='?page=sales_history' class='btn btn-primary'>Return to Sales History</a></p>";
        } else {
            $content .= "<div class='alert alert-danger'>Error updating payment status: " . $conn->error . "</div>";
        }
        
        $conn->close();
    }
    
    if ($action == 'reduce_price' && isset($_GET['item_id'])) {
        $item_id = $_GET['item_id'];
        
        $conn = connectDB();
        
        // Get current price
        $sql = "SELECT asking_price, min_price FROM items WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $item = $result->fetch_assoc();
            $current_price = $item['asking_price'];
            $min_price = $item['min_price'];
            
            // Calculate 20% reduction
            $reduced_price = $current_price * 0.8;
            
            // Don't go below minimum price
            if ($reduced_price < $min_price) {
                $reduced_price = $min_price;
            }
            
            $content .= "<h2 class='mt-4'>Reduce Price</h2>";
            
            $content .= "<form method='post' action='?action=confirm_price_reduction&item_id={$item_id}'>";
            $content .= "<div class='card mb-3'>";
            $content .= "<div class='card-header'><strong>Price Reduction</strong></div>";
            $content .= "<div class='card-body'>";
            $content .= "<p><strong>Current Price:</strong> $" . number_format($current_price, 2) . "</p>";
            $content .= "<p><strong>Recommended Reduction:</strong> $" . number_format($reduced_price, 2) . " (20% reduction)</p>";
            $content .= "<p><strong>Minimum Acceptable Price:</strong> $" . number_format($min_price, 2) . "</p>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='new_price'>New Price ($):</label>";
            $content .= "<input type='number' name='new_price' id='new_price' step='0.01' min='{$min_price}' max='{$current_price}' value='{$reduced_price}' required class='form-control'>";
            $content .= "</div>";
            
            $content .= "</div></div>";
            
            $content .= "<button type='submit' class='btn btn-warning'>Confirm Price Reduction</button>";
            $content .= " <a href='?page=aging_inventory' class='btn btn-secondary'>Cancel</a>";
            $content .= "</form>";
        } else {
            $content .= "<div class='alert alert-danger'>Item not found!</div>";
        }
        
        $conn->close();
    }
    
    if ($action == 'confirm_price_reduction' && isset($_GET['item_id']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $item_id = $_GET['item_id'];
        $new_price = $_POST['new_price'];
        
        $conn = connectDB();
        
        // Update price
        $sql = "UPDATE items SET asking_price = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("di", $new_price, $item_id);
        
        if ($stmt->execute()) {
            $content .= "<div class='alert alert-success'>Price reduced successfully!</div>";
            $content .= "<p><a href='?page=aging_inventory' class='btn btn-primary'>Return to Aging Inventory</a></p>";
        } else {
            $content .= "<div class='alert alert-danger'>Error updating price: " . $conn->error . "</div>";
        }
        
        $conn->close();
    }
    
    if ($action == 'mark_pickup' && isset($_GET['item_id'])) {
        $item_id = $_GET['item_id'];
        
        $conn = connectDB();
        
        // Update item status
        $sql = "UPDATE items SET status = 'pickup' WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $item_id);
        
        if ($stmt->execute()) {
            $content .= "<div class='alert alert-success'>Item marked for pickup!</div>";
            $content .= "<p><a href='?page=aging_inventory' class='btn btn-primary'>Return to Aging Inventory</a></p>";
        } else {
            $content .= "<div class='alert alert-danger'>Error updating status: " . $conn->error . "</div>";
        }
        
        $conn->close();
    }
    
if ($action == 'add_promotion' && isset($_GET['item_id'])) {
    $item_id = $_GET['item_id'];
    
    $conn = connectDB();
    
    // Get item details
    $sql = "SELECT i.*, c.name as consignor_name 
           FROM items i
           JOIN consignors c ON i.consignor_id = c.id
           WHERE i.id = ?";
           
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        
        $content .= "<h2 class='mt-4'>Add Promotion</h2>";
            
            $content .= "<form method='post' action='?action=save_promotion&item_id={$item_id}'>";
            $content .= "<div class='card mb-3'>";
            $content .= "<div class='card-header'><strong>Item Details</strong></div>";
            $content .= "<div class='card-body'>";
            $content .= "<p><strong>Description:</strong> {$item['description']}</p>";
            $content .= "<p><strong>Make/Model:</strong> {$item['make_model']}</p>";
            $content .= "<p><strong>Consignor:</strong> {$item['consignor_name']}</p>";
            $content .= "<p><strong>Current Price:</strong> $" . number_format($item['asking_price'], 2) . "</p>";
            $content .= "</div></div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='promotion_type'>Promotion Type:</label>";
            $content .= "<select name='promotion_type' id='promotion_type' class='form-control'>";
            $content .= "<option value='featured'>Featured Item</option>";
            $content .= "<option value='special'>Special Deal</option>";
            $content .= "<option value='discount'>Discount Offer</option>";
            $content .= "</select>";
            $content .= "</div>";
            
            $content .= "<div class='form-group'>";
            $content .= "<label for='promotion_notes'>Promotion Notes:</label>";
            $content .= "<textarea name='promotion_notes' id='promotion_notes' class='form-control'></textarea>";
            $content .= "</div>";
            
            $content .= "<button type='submit' class='btn btn-warning'>Save Promotion</button>";
            $content .= " <a href='?page=aging_inventory' class='btn btn-secondary'>Cancel</a>";
            $content .= "</form>";
        } else {
            $content .= "<div class='alert alert-danger'>Item not found!</div>";
        }
        
        $conn->close();
    }
    
    if ($action == 'save_promotion' && isset($_GET['item_id']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $item_id = $_GET['item_id'];
        $promotion_type = $_POST['promotion_type'];
        $promotion_notes = $_POST['promotion_notes'];
        
        $conn = connectDB();
        
        // Update item notes
        $sql = "UPDATE items SET notes = CONCAT(notes, '\n\nPROMOTION: ', ?, ' - ', ?) WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $promotion_type, $promotion_notes, $item_id);
        
        if ($stmt->execute()) {
            $content .= "<div class='alert alert-success'>Promotion added successfully!</div>";
            $content .= "<p><a href='?page=aging_inventory' class='btn btn-primary'>Return to Aging Inventory</a></p>";
        } else {
            $content .= "<div class='alert alert-danger'>Error adding promotion: " . $conn->error . "</div>";
        }
        
        $conn->close();
    }

// ====================[ ARCHIVE RENTAL ]====================
if ($action === 'archive_rental' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int) $_GET['id'];
    $conn = connectDB();

    $stmt = $conn->prepare("UPDATE rentals SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        header("Location: ?page=completed_rentals&msg=archived");
        exit;
    } else {
        $content .= "<div class='alert alert-danger'>Error archiving rental: {$conn->error}</div>";
    }

    $stmt->close();
    $conn->close();
}

// ====================[ RESTORE RENTAL ]====================
if ($action === 'restore_rental' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int) $_GET['id'];
    $conn = connectDB();

    $stmt = $conn->prepare("UPDATE rentals SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        header("Location: ?page=archived_rentals&msg=restored");
        exit;
    } else {
        $content .= "<div class='alert alert-danger'>Error restoring rental: {$conn->error}</div>";
    }

    $stmt->close();
    $conn->close();
}


// ====================[ DELETE RENTAL ]====================
if ($action === 'delete_rental' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = (int) $_GET['id'];
    $conn = connectDB();
    $stmt = $conn->prepare("DELETE FROM rentals WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: ?page=rentals&msg=deleted");
        exit;
    } else {
        $content .= "<div class='alert alert-danger'>Error deleting rental: {$conn->error}</div>";
    }
    $stmt->close();
    $conn->close();
}

    // ====================[ DELETE SALE ]====================
    if ($action === 'delete_sale' && isset($_GET['sale_id']) && is_numeric($_GET['sale_id'])) {
        $id = (int) $_GET['sale_id'];
    $conn = connectDB();
    $stmt = $conn->prepare("DELETE FROM sales WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: ?page=sales_history&msg=deleted");
        exit;
    } else {
        $content .= "<div class='alert alert-danger'>Error deleting sale: {$conn->error}</div>";
    }
    $stmt->close();
    $conn->close();
}


// // ====================[ DATABASE SETUP ]====================
// Make sure refunds table has all necessary columns
function setupRefundsTable() {
    $conn = connectDB();
    
    // Create refunds table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS refunds (
        id INT(11) NOT NULL AUTO_INCREMENT,
        type VARCHAR(10) NOT NULL, 
        reference_id INT(11) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        reason TEXT NOT NULL,
        date_issued DATE NOT NULL,
        issued_by VARCHAR(100) NOT NULL,
        returned_to_inventory TINYINT(1) DEFAULT 0,
        issued_credit TINYINT(1) DEFAULT 0,
        PRIMARY KEY (id)
    )";
    
    $conn->query($sql);
    
    // Check if issued_credit column exists, add if it doesn't
    $result = $conn->query("SHOW COLUMNS FROM refunds LIKE 'issued_credit'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE refunds ADD COLUMN issued_credit TINYINT(1) DEFAULT 0");
    }
    
    $conn->close();
}
// Call this in your main setup function or at the beginning of your script
setupRefundsTable();


// =======================[ ACTION: delete_consignor ]=======================
if ($action == 'delete_consignor' && isset($_GET['consignor_id'])) {
    $consignor_id = intval($_GET['consignor_id']);
    $conn = connectDB();
    // Optional: Only delete if they have no active or sold items
    $check = $conn->query("SELECT COUNT(*) as total FROM items WHERE consignor_id = $consignor_id");
    $count = $check->fetch_assoc()['total'];
    if ($count == 0) {
        $stmt = $conn->prepare("DELETE FROM consignors WHERE id = ?");
        $stmt->bind_param("i", $consignor_id);
        if ($stmt->execute()) {
            header("Location: ?page=consignors&msg=deleted");
            exit;
        } else {
            $content .= "<div class='alert alert-danger'>Error deleting consignor: " . $conn->error . "</div>";
        }
        $stmt->close();
    } else {
        $content .= "<div class='alert alert-warning'>This consignor cannot be deleted. They are associated with active or sold items.</div>";
    }
    $conn->close();
}


// ====================[ ACTION: ISSUE REFUND ]====================
if ($action === 'issue_refund' && isset($_GET['type']) && isset($_GET['id'])) {
    $type = $_GET['type'];
    $ref_id = (int) $_GET['id'];
    $conn = connectDB();
    $content = ''; // Initialize $content variable

    // Ensure required columns exist in refunds table
    $columnsToAdd = [
        "customer_name VARCHAR(255)",
        "consignor_name VARCHAR(255)",
        "item_description VARCHAR(255)",
        "item_make_model VARCHAR(255)",
        "damage_fee DECIMAL(10,2)",
        "delivery_fee DECIMAL(10,2)",
        "fuel_surcharge DECIMAL(10,2)",
        "cleaning_fee DECIMAL(10,2)",
        "late_fee DECIMAL(10,2)",
        "misc_fee DECIMAL(10,2)"
    ];
    foreach ($columnsToAdd as $colDef) {
        preg_match('/^(\w+)/', $colDef, $matches);
        $colName = $matches[1];
        $result = $conn->query("SHOW COLUMNS FROM refunds LIKE '{$colName}'");
        if ($result->num_rows == 0) {
            $conn->query("ALTER TABLE refunds ADD COLUMN {$colDef}");
        }
    }

    // Check if deposit column exists in rentals table
    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'deposit'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN deposit DECIMAL(10,2) DEFAULT 0.00");
    }

    // Check if deposit_returned column exists in rentals table
    $result = $conn->query("SHOW COLUMNS FROM rentals LIKE 'deposit_returned'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE rentals ADD COLUMN deposit_returned DECIMAL(10,2) DEFAULT 0.00");
    }

    // Check if this is a rental deposit refund
    $is_deposit_refund = ($type === 'rental');
    $deposit_amount = 0;
    $deposit_returned = 0;
    
    if ($is_deposit_refund) {
        // Get deposit amount and any already returned amount
        $deposit_query_sql = "SELECT deposit, IFNULL(deposit_returned, 0) AS deposit_returned FROM rentals WHERE id = ?";
        $deposit_query = $conn->prepare($deposit_query_sql);
        
        if ($deposit_query) {
            $deposit_query->bind_param("i", $ref_id);
            $deposit_query->execute();
            $deposit_result = $deposit_query->get_result();
            if ($deposit_result && $deposit_result->num_rows > 0) {
                $deposit_row = $deposit_result->fetch_assoc();
                $deposit_amount = floatval($deposit_row['deposit']);
                $deposit_returned = floatval($deposit_row['deposit_returned']);
            }
            $deposit_query->close();
        }
    }
    
    // Available deposit is original deposit minus what's already been returned
    $available_deposit = max(0, $deposit_amount - $deposit_returned);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Process the form submission
        $damage_fee     = isset($_POST['damage_fee']) ? floatval($_POST['damage_fee']) : 0.00;
        $delivery_fee   = isset($_POST['delivery_fee']) ? floatval($_POST['delivery_fee']) : 0.00;
        $fuel_surcharge = isset($_POST['fuel_surcharge']) ? floatval($_POST['fuel_surcharge']) : 0.00;
        $cleaning_fee   = isset($_POST['cleaning_fee']) ? floatval($_POST['cleaning_fee']) : 0.00;
        $late_fee       = isset($_POST['late_fee']) ? floatval($_POST['late_fee']) : 0.00;
        $misc_fee       = isset($_POST['misc_fee']) ? floatval($_POST['misc_fee']) : 0.00;

        // Calculate total fees and refund amount
        $total_fees = $damage_fee + $delivery_fee + $fuel_surcharge + $cleaning_fee + $late_fee + $misc_fee;
        
        // For deposit refunds, refund is deposit minus fees (within available limit)
        // For other refunds, use the amount specified or calculated fees
        if ($type === 'rental' && $available_deposit > 0) {
            // Refund amount cannot exceed available deposit
            $amount = min($available_deposit, max(0, $available_deposit - $total_fees));
            
            // Update deposit_returned in rentals table
            $new_deposit_returned = $deposit_returned + $amount;
            $update_sql = "UPDATE rentals SET deposit_returned = ? WHERE id = ?";
            $update_rental = $conn->prepare($update_sql);
            
            if ($update_rental) {
                $update_rental->bind_param("di", $new_deposit_returned, $ref_id);
                if (!$update_rental->execute()) {
                    $content .= "<div class='alert alert-warning'>Failed to update deposit returned: " . $update_rental->error . "</div>";
                }
                $update_rental->close();
            }
        } else {
            // For non-deposit refunds, use the amount from POST or calculate from fees
            $amount = isset($_POST['amount']) && floatval($_POST['amount']) > 0 ? floatval($_POST['amount']) : $total_fees;
        }

        $reason = $_POST['reason'];
        $issued_by = $_POST['issued_by'];
        $date_issued = date('Y-m-d');
        $return_to_inventory = isset($_POST['return_to_inventory']) ? 1 : 0;
        $issue_credit = isset($_POST['issue_credit']) ? 1 : 0;

        $query = ($type === 'sale') ?
            "SELECT s.buyer_name AS customer_name, i.description, i.make_model, IFNULL(c.name, 'House Inventory') AS consignor_name, i.id AS item_id FROM sales s JOIN items i ON s.item_id = i.id LEFT JOIN consignors c ON i.consignor_id = c.id WHERE s.id = ?" :
            "SELECT t.renter_name AS customer_name, i.description, i.make_model, IFNULL(c.name, 'House Inventory') AS consignor_name, i.id AS item_id FROM rentals t JOIN items i ON t.item_id = i.id LEFT JOIN consignors c ON i.consignor_id = c.id WHERE t.id = ?";

        $infoStmt = $conn->prepare($query);
        if (!$infoStmt) {
            $content .= "<div class='alert alert-danger'>Failed to prepare query: " . $conn->error . "</div>";
        } else {
            $infoStmt->bind_param("i", $ref_id);
            $infoStmt->execute();
            $result = $infoStmt->get_result();

            if ($result->num_rows === 0) {
                $content .= "<div class='alert alert-danger'>Transaction not found. Refund not issued.</div>";
            } else {
                $info = $result->fetch_assoc();
                $customer_name    = $info['customer_name'];
                $consignor_name   = $info['consignor_name'];
                $item_description = $info['description'];
                $item_make_model  = $info['make_model'];
                $item_id          = $info['item_id'];

                $stmt = $conn->prepare("INSERT INTO refunds (
                    type, reference_id, amount, reason, date_issued, issued_by,
                    returned_to_inventory, issued_credit, customer_name,
                    consignor_name, item_description, item_make_model,
                    damage_fee, delivery_fee, fuel_surcharge, cleaning_fee, late_fee, misc_fee
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                if (!$stmt) {
                    $content .= "<div class='alert alert-danger'>Failed to prepare insert: " . $conn->error . "</div>";
                } else {
                    $stmt->bind_param(
                        "sidsssisssssdddddd",
                        $type, $ref_id, $amount, $reason, $date_issued, $issued_by,
                        $return_to_inventory, $issue_credit, $customer_name,
                        $consignor_name, $item_description, $item_make_model,
                        $damage_fee, $delivery_fee, $fuel_surcharge, $cleaning_fee, $late_fee, $misc_fee
                    );
                    
                    if (!$stmt->execute()) {
                        $content .= "<div class='alert alert-danger'>Failed to create refund: " . $stmt->error . "</div>";
                    } else {
                        if ($return_to_inventory) {
                            $conn->query("UPDATE items SET status = 'active' WHERE id = {$item_id}");
                            if ($type === 'sale') {
                                $conn->query("CREATE TABLE IF NOT EXISTS archived_sales LIKE sales");
                                $conn->query("INSERT INTO archived_sales SELECT * FROM sales WHERE id = {$ref_id}");
                                $conn->query("DELETE FROM sales WHERE id = {$ref_id}");
                            } else {
                                $conn->query("CREATE TABLE IF NOT EXISTS archived_rentals LIKE rentals");
                                $conn->query("INSERT INTO archived_rentals SELECT * FROM rentals WHERE id = {$ref_id}");
                                $conn->query("DELETE FROM rentals WHERE id = {$ref_id}");
                            }
                        }

                        if ($issue_credit && !empty($customer_name)) {
                            $conn->query("CREATE TABLE IF NOT EXISTS customer_credits (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                customer_name VARCHAR(255),
                                amount DECIMAL(10,2),
                                date_added DATE
                            )");
                            $stmtCredit = $conn->prepare("INSERT INTO customer_credits (customer_name, amount, date_added) VALUES (?, ?, ?)");
                            if ($stmtCredit) {
                                $stmtCredit->bind_param("sds", $customer_name, $amount, $date_issued);
                                $stmtCredit->execute();
                                $stmtCredit->close();
                            }
                        }

                        $content .= "<div class='alert alert-success'>
                            <strong>Refund successful!</strong> $" . number_format($amount, 2) . " has been refunded."
                            . ($issue_credit ? " Store credit issued to $customer_name." : "") . 
                            "<div class='mt-3'>
                                <a href='?page=print_return_receipt&rental_id={$ref_id}' class='btn btn-info' target='_blank'>Print Return Receipt</a>
                                <a href='?page=" . ($type === 'sale' ? "sales_history" : "rentals") . "' class='btn btn-secondary'>Return to " . ($type === 'sale' ? "Sales" : "Rentals") . "</a>
                            </div>
                        </div>";
                        
                        $stmt->close();
                    }
                }
            }
            $infoStmt->close();
        }
    }

    // Transaction summary + form
    // First check if the necessary columns exist before querying them
    $query = ($type === 'sale') ?
        "SELECT s.sale_date, s.sale_price, s.commission_amount, s.buyer_name, 
                i.description, i.make_model, IFNULL(c.name, 'House Inventory') AS consignor
         FROM sales s
         JOIN items i ON s.item_id = i.id
         LEFT JOIN consignors c ON i.consignor_id = c.id
         WHERE s.id = ?" :
        "SELECT t.rental_start, t.rental_end, t.total_amount, t.renter_name, t.renter_contact,
                i.description, i.make_model, IFNULL(c.name, 'House Inventory') AS consignor
         FROM rentals t
         JOIN items i ON t.item_id = i.id
         LEFT JOIN consignors c ON i.consignor_id = c.id
         WHERE t.id = ?";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        $content .= "<div class='alert alert-danger'>Failed to prepare query: " . $conn->error . "</div>";
    } else {
        $stmt->bind_param("i", $ref_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $transaction = $result->fetch_assoc();
            
            // Get deposit information separately since the column might be new
            if ($type === 'rental') {
                $deposit_query = $conn->prepare("SELECT IFNULL(deposit, 0) as deposit, IFNULL(deposit_returned, 0) as deposit_returned FROM rentals WHERE id = ?");
                if ($deposit_query) {
                    $deposit_query->bind_param("i", $ref_id);
                    $deposit_query->execute();
                    $deposit_result = $deposit_query->get_result();
                    if ($deposit_result && $deposit_result->num_rows > 0) {
                        $deposit_info = $deposit_result->fetch_assoc();
                        $transaction['deposit'] = $deposit_info['deposit'];
                        $transaction['deposit_returned'] = $deposit_info['deposit_returned'];
                    }
                    $deposit_query->close();
                }
            }
            
            $content .= "<div class='card mb-3'><div class='card-body'>";
            $content .= "<h5>Transaction Summary</h5><ul class='list-unstyled'>";
        
            if ($type === 'sale') {
                $content .= "<li><strong>Customer:</strong> " . htmlspecialchars($transaction['buyer_name']) . "</li>
                             <li><strong>Consignor:</strong> " . htmlspecialchars($transaction['consignor']) . "</li>
                             <li><strong>Item:</strong> " . htmlspecialchars($transaction['description']) . " (" . htmlspecialchars($transaction['make_model']) . ")</li>
                             <li><strong>Sale Date:</strong> " . date('m/d/Y', strtotime($transaction['sale_date'])) . "</li>
                             <li><strong>Sale Price:</strong> $" . number_format($transaction['sale_price'], 2) . "</li>
                             <li><strong>Commission:</strong> $" . number_format($transaction['commission_amount'], 2) . "</li>";
            } else {
                // Get the rental fee (total_amount)
                $rental_fee = isset($transaction['total_amount']) ? floatval($transaction['total_amount']) : 0;
                
                $content .= "<li><strong>Renter:</strong> " . htmlspecialchars($transaction['renter_name']) . " (" . htmlspecialchars($transaction['renter_contact']) . ")</li>
                             <li><strong>Consignor:</strong> " . htmlspecialchars($transaction['consignor']) . "</li>
                             <li><strong>Item:</strong> " . htmlspecialchars($transaction['description']) . " (" . htmlspecialchars($transaction['make_model']) . ")</li>
                             <li><strong>Rental Period:</strong> " . date('m/d/Y', strtotime($transaction['rental_start'])) .
                             " to " . date('m/d/Y', strtotime($transaction['rental_end'])) . "</li>
                             <li><strong>Total Amount:</strong> $" . number_format($rental_fee, 2) . "</li>";
                
                // Show deposit information for rental
                if (isset($transaction['deposit']) && floatval($transaction['deposit']) > 0) {
                    $deposit = floatval($transaction['deposit']);
                    $deposit_returned = isset($transaction['deposit_returned']) ? floatval($transaction['deposit_returned']) : 0;
                    $available_deposit = max(0, $deposit - $deposit_returned);
                    
                    $content .= "<li><strong>Security Deposit:</strong> $" . number_format($deposit, 2) . "</li>";
                    
                    // Show if deposit was already returned
                    if ($deposit_returned > 0) {
                        $content .= "<li><strong>Previously Returned:</strong> $" . number_format($deposit_returned, 2) . "</li>";
                        $content .= "<li><strong>Remaining Available:</strong> $" . number_format($available_deposit, 2) . 
                                    " (deposit) + $" . number_format($rental_fee, 2) . " (rental fee)</li>";
                    }
                }
            }
        
            $content .= "</ul></div></div>";

            // Issue refund form
            $content .= "<h2 class='mt-4'>Issue " . ucfirst($type) . " Refund</h2>";
            
            // Show deposit refund alert for rental deposits
            if ($type === 'rental' && isset($transaction['deposit']) && floatval($transaction['deposit']) > 0) {
                $deposit = floatval($transaction['deposit']);
                $deposit_returned = isset($transaction['deposit_returned']) ? floatval($transaction['deposit_returned']) : 0;
                $available_deposit = max(0, $deposit - $deposit_returned);
                $rental_fee = isset($transaction['total_amount']) ? floatval($transaction['total_amount']) : 0;
                
                if ($available_deposit > 0) {
                    $content .= "<div class='alert alert-info'>
                        <strong>Deposit Information:</strong>
                        <p>Original Deposit: $" . number_format($deposit, 2) . "</p>";
                    
                    if ($deposit_returned > 0) {
                        $content .= "<p>Already Returned: $" . number_format($deposit_returned, 2) . "<br>";
                        $content .= "Available for Refund: $" . number_format($available_deposit, 2) . 
                                    " (deposit) + $" . number_format($rental_fee, 2) . " (rental fee)</p>";
                    }
                    
                    $content .= "<p>Use the form below to deduct any fees from the deposit before refunding.</p>
                    </div>";
                } else {
                    $content .= "<div class='alert alert-warning'>
                        <strong>Deposit fully refunded!</strong> The entire security deposit of $" . number_format($deposit, 2) . 
                        " has already been returned. You can still issue a refund from the rental fee of $" . 
                        number_format($rental_fee, 2) . " if needed.
                    </div>";
                }
            }
            
            $content .= "<form method='post' id='refundForm'>";
            
            // Refund amount field
            $content .= "<div class='form-group'>
                <label>Refund Amount </label>
                <input type='number' name='amount' id='refund_amount' class='form-control' step='0.01'" . 
                (($type === 'rental' && $available_deposit > 0) ? " readonly value='" . number_format($available_deposit, 2, '.', '') . "'" : "") . ">
            </div>
            <div id='refund_breakdown' class='mb-3 small text-muted'></div>";

            // Reason and issued by fields
            $content .= "<div class='form-group'>
                <label>Reason</label>
                <textarea name='reason' class='form-control' required></textarea>
            </div>
            <div class='form-group'>
                <label>Issued By</label>
                <input type='text' name='issued_by' class='form-control' required>
            </div>";

            // Sale-specific options
            if ($type === 'sale') {
                $content .= "
                <div class='form-group mt-4'>
                    <label><strong>Refund Options:</strong></label>
                    <div class='form-check'>
                        <input type='checkbox' name='return_to_inventory' class='form-check-input' id='return_to_inventory'>
                        <label for='return_to_inventory' class='form-check-label'>Return item to inventory</label>
                    </div>
                    <div class='form-check mb-3'>
                        <input type='checkbox' name='issue_credit' class='form-check-input' id='issue_credit'>
                        <label for='issue_credit' class='form-check-label'>Issue store credit</label>
                    </div>
                </div>";
            }

            // Rental-specific fee fields
            if ($type === 'rental') {
                $content .= "
                <div class='form-group mt-4'>
                    <label><strong>Rental-Related Charges (Enter amount if applicable):</strong></label>
                    <div class='form-row mb-2'>
                        <div class='col'>
                            <label>Damage Fee <small class='text-muted'>(Suggested: \$100)</small></label>
                            <input type='number' step='0.01' name='damage_fee' class='form-control refund-charge' value='0.00'>
                        </div>
                        <div class='col'>
                            <label>Delivery/Pickup <small class='text-muted'>(Suggested: \$50)</small></label>
                            <input type='number' step='0.01' name='delivery_fee' class='form-control refund-charge' value='0.00'>
                        </div>
                    </div>
                    <div class='form-row mb-2'>
                        <div class='col'>
                            <label>Fuel Surcharge <small class='text-muted'>(Suggested: \$25)</small></label>
                            <input type='number' step='0.01' name='fuel_surcharge' class='form-control refund-charge' value='0.00'>
                        </div>
                        <div class='col'>
                            <label>Cleaning Fee <small class='text-muted'>(Suggested: \$50)</small></label>
                            <input type='number' step='0.01' name='cleaning_fee' class='form-control refund-charge' value='0.00'>
                        </div>
                    </div>
                    <div class='form-row mb-2'>
                        <div class='col'>
                            <label>Late Fee <small class='text-muted'>(Suggested: 1.5× daily rate)</small></label>
                            <input type='number' step='0.01' name='late_fee' class='form-control refund-charge' value='0.00'>
                        </div>
                        <div class='col'>
                            <label>Misc. Charge <small class='text-muted'>(Optional)</small></label>
                            <input type='number' step='0.01' name='misc_fee' class='form-control refund-charge' value='0.00'>
                        </div>
                    </div>
                </div>";
            }

            // Form submission buttons
            $content .= "
                <button type='submit' class='btn btn-primary'>Submit Refund</button>
                <a href='?page=" . ($type === 'sale' ? "sales_history" : "rentals") . "' class='btn btn-secondary'>Cancel</a>
            </form>";
            
            // Add JavaScript for deposit refunds
            if ($type === 'rental' && isset($available_deposit)) {
                $rental_fee = isset($transaction['total_amount']) ? floatval($transaction['total_amount']) : 0;
                
                $content .= '
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const fields = [
            "damage_fee",
            "delivery_fee",
            "fuel_surcharge",
            "cleaning_fee",
            "late_fee",
            "misc_fee"
        ];
        const depositAmount = ' . $available_deposit . ';
        const rentalAmount = ' . $rental_fee . ';
    
        function updateRefundTotal() {
            let totalFees = 0;
            let breakdown = [];
    
            fields.forEach(name => {
                const input = document.querySelector(`input[name="${name}"]`);
                if (input && input.value) {
                    const val = parseFloat(input.value) || 0;
                    totalFees += val;
                    if (val > 0) {
                        const label = input.closest("div").querySelector("label").innerText.split("(")[0].trim();
                        breakdown.push(`${label}: $${val.toFixed(2)}`);
                    }
                }
            });
            
            // Calculate refund based on available deposit and rental fee
            let refundAmount = 0;
            
            // If there\'s deposit available, use that first
            if (depositAmount > 0) {
                refundAmount = Math.max(0, depositAmount - totalFees);
                
                // If fees exceed deposit and rental fee is available, use that too
                if (refundAmount === 0 && totalFees > depositAmount && rentalAmount > 0) {
                    refundAmount = Math.min(rentalAmount, totalFees - depositAmount);
                }
            } else if (rentalAmount > 0) {
                // No deposit available, use rental fee
                refundAmount = Math.min(rentalAmount, totalFees);
            }
            
            // If we\'re not deducting fees, make the full amount available
            if (totalFees === 0) {
                if (depositAmount > 0) {
                    refundAmount = depositAmount;
                } else {
                    refundAmount = rentalAmount;
                }
            }
            
            document.getElementById("refund_amount").value = refundAmount.toFixed(2);
            
            // Show breakdown
            let breakdownText = "";
            if (depositAmount > 0 || rentalAmount > 0) {
                breakdownText += "Available Funds: ";
                
                if (depositAmount > 0) {
                    breakdownText += "$" + depositAmount.toFixed(2) + " (deposit)";
                    if (rentalAmount > 0) {
                        breakdownText += " + ";
                    }
                }
                
                if (rentalAmount > 0) {
                    breakdownText += "$" + rentalAmount.toFixed(2) + " (rental fee)";
                }
                
                if (breakdown.length > 0) {
                    breakdownText += "<br>Fees deducted: " + breakdown.join(", ");
                    breakdownText += "<br>Total fees: $" + totalFees.toFixed(2);
                    breakdownText += "<br>Refund amount: $" + refundAmount.toFixed(2);
                }
            }
            document.getElementById("refund_breakdown").innerHTML = breakdownText;
        }
    
        document.querySelectorAll(".refund-charge").forEach(input => {
            input.addEventListener("input", updateRefundTotal);
        });
    
        updateRefundTotal(); // Initialize on page load
    });
    </script>';
            } else if ($type === 'rental') {
                // Standard rental refund JS that adds up the fees rather than subtracting
                $content .= '
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const fields = [
            "damage_fee",
            "delivery_fee",
            "fuel_surcharge",
            "cleaning_fee",
            "late_fee",
            "misc_fee"
        ];
    
        function updateRefundTotal() {
            let total = 0;
            let breakdown = [];
    
            fields.forEach(name => {
                const input = document.querySelector(`input[name="${name}"]`);
                if (input && input.value) {
                    const val = parseFloat(input.value) || 0;
                    total += val;
                    if (val > 0) {
                        const label = input.closest("div").querySelector("label").innerText.split("(")[0].trim();
                        breakdown.push(`${label}: $${val.toFixed(2)}`);
                    }
                }
            });
    
            document.getElementById("refund_amount").value = total.toFixed(2);
            document.getElementById("refund_breakdown").innerHTML = breakdown.join(", ");
        }
    
        document.querySelectorAll(".refund-charge").forEach(input => {
            input.addEventListener("input", updateRefundTotal);
        });
    
        updateRefundTotal(); // Initialize on page load
    });
    </script>';
            }
        } else {
            $content .= "<div class='alert alert-danger'>Transaction not found.</div>";
        }
        
        $stmt->close();
    }
    
    $conn->close();
}

// ====================[ ACTION: REFUNDS PAGE ]====================
if (isset($page) && $page === 'refunds') {
    $content = ''; // Initialize $content variable
    $conn = connectDB();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_refund_id'])) {
        $delete_id = intval($_POST['delete_refund_id']);
        $conn->query("DELETE FROM refunds WHERE id = $delete_id");
    }
    
    $sql = "SELECT * FROM refunds ORDER BY date_issued DESC";
    $result = $conn->query($sql);
    $content .= "<h2 class='mt-4'>Refund History</h2>";
    
    if ($result && $result->num_rows > 0) {
        $content .= "<div class='table-responsive'>";
        $content .= "<table class='table table-sm table-bordered'>";
        $content .= "<thead><tr>
            <th>Date</th>
            <th>Type</th>
            <th>Ref ID</th>
            <th>Customer</th>
            <th>Consignor</th>
            <th>Item</th>
            <th>Amount</th>
            <th>Reason</th>
            <th>Charges</th>
            <th>Issued By</th>
            <th>Status</th>
        </tr></thead><tbody>";
        
        while ($row = $result->fetch_assoc()) {
            $status_badges = [];
            if ($row['returned_to_inventory']) {
                $status_badges[] = "<span class='badge badge-success'>Returned</span>";
            }
            if ($row['issued_credit']) {
                $status_badges[] = "<span class='badge badge-info'>Credit</span>";
            }
            if (empty($status_badges)) {
                $status_badges[] = "<span class='badge badge-secondary'>None</span>";
            }
            $status_display = implode(' ', $status_badges);
            $ref_id = str_pad($row['reference_id'], 5, '0', STR_PAD_LEFT);
            $charge_labels = [
                'damage_fee' => 'Damage Deposit',
                'delivery_fee' => 'Delivery/Pickup',
                'fuel_surcharge' => 'Fuel Surcharge',
                'cleaning_fee' => 'Cleaning Fee',
                'late_fee' => 'Late Fee',
                'misc_fee' => 'Misc. Charge'
            ];
            $charges = [];
            foreach ($charge_labels as $col => $label) {
                if (isset($row[$col]) && $row[$col] > 0) {
                    $charges[] = "$label: $" . number_format($row[$col], 2);
                }
            }
            
            $charges_display = !empty($charges) ? implode('<br>', $charges) : '-';
            $content .= "<tr>
                <td>" . date('m/d/Y', strtotime($row['date_issued'])) . "</td>
                <td>" . htmlspecialchars(ucfirst($row['type'])) . "</td>
                <td>R-$ref_id</td>
                <td>" . htmlspecialchars($row['customer_name']) . "</td>
                <td>" . htmlspecialchars($row['consignor_name']) . "</td>
                <td>" . htmlspecialchars($row['item_description']) . " <small>(" . htmlspecialchars($row['item_make_model']) . ")</small></td>
                <td>$" . number_format($row['amount'], 2) . "</td>
                <td>" . nl2br(htmlspecialchars($row['reason'])) . "</td>
                <td>" . $charges_display . "</td>
                <td>" . htmlspecialchars($row['issued_by']) . "</td>
                <td>
                    $status_display
                    <form method='post' action='?page=refunds' style='display:inline-block;' onsubmit='return confirm(\"Are you sure you want to delete this refund?\");'>
                        <input type='hidden' name='delete_refund_id' value='{$row['id']}'>
                        <button type='submit' class='btn btn-sm btn-danger ml-2'>Delete</button>
                    </form>
                </td>
            </tr>";
        }
        $content .= "</tbody></table></div>";
    } else {
        $content .= "<div class='alert alert-info'>No refunds found.</div>";
    }
    $conn->close();
}

// ====================[ ACTION: REFUND DETAIL VIEW ]====================
if (isset($action) && $action === 'refund_detail' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $content = ''; // Initialize $content variable
    $refund_id = (int) $_GET['id'];
    $conn = connectDB();
    $stmt = $conn->prepare("SELECT * FROM refunds WHERE id = ?");
    $stmt->bind_param("i", $refund_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $returned_badge = $row['returned_to_inventory'] ? "<span class='badge badge-success'>Yes</span>" : "<span class='badge badge-secondary'>No</span>";
        $credit_badge = $row['issued_credit'] ? "<span class='badge badge-info'>Yes</span>" : "<span class='badge badge-secondary'>No</span>";

        $charge_labels = [
            'damage_fee' => 'Damage Deposit',
            'delivery_fee' => 'Delivery/Pickup',
            'fuel_surcharge' => 'Fuel Surcharge',
            'cleaning_fee' => 'Cleaning Fee',
            'late_fee' => 'Late Fee',
            'misc_fee' => 'Misc. Charge'
        ];
        $charges = [];
        foreach ($charge_labels as $col => $label) {
            if (isset($row[$col]) && $row[$col] > 0) {
                $charges[] = "$label: $" . number_format($row[$col], 2);
            }
        }
        
        $charges_display = !empty($charges) ? implode('<br>', $charges) : '-';

        $content .= "<h2 class='mt-4'>Refund Details</h2>
        <table class='table table-bordered table-sm'>
            <tr><th>Date Issued</th><td>" . date('m/d/Y', strtotime($row['date_issued'])) . "</td></tr>
            <tr><th>Type</th><td>" . ucfirst($row['type']) . "</td></tr>
            <tr><th>Reference ID</th><td>R-" . str_pad($row['reference_id'], 5, '0', STR_PAD_LEFT) . "</td></tr>
            <tr><th>Customer</th><td>" . htmlspecialchars($row['customer_name']) . "</td></tr>
            <tr><th>Consignor</th><td>" . htmlspecialchars($row['consignor_name']) . "</td></tr>
            <tr><th>Item</th><td>" . htmlspecialchars($row['item_description']) . (!empty($row['item_make_model']) ? " <small>(" . htmlspecialchars($row['item_make_model']) . ")</small>" : '') . "</td></tr>
            <tr><th>Amount</th><td>$" . number_format($row['amount'], 2) . "</td></tr>
            <tr><th>Returned to Inventory</th><td>{$returned_badge}</td></tr>
            <tr><th>Issued Store Credit</th><td>{$credit_badge}</td></tr>
            <tr><th>Charges</th><td>{$charges_display}</td></tr>
            <tr><th>Reason</th><td>" . nl2br(htmlspecialchars($row['reason'])) . "</td></tr>
            <tr><th>Issued By</th><td>" . htmlspecialchars($row['issued_by']) . "</td></tr>
        </table>
        <a href='?page=refunds' class='btn btn-secondary'>Back to Refund History</a>";
    } else {
        $content .= "<div class='alert alert-danger'>Refund record not found.</div>";
    }
    $stmt->close();
    $conn->close();
}

// ====================[ DATABASE SETUP: Add trade columns if missing ]====================
function setupTradeFields() {
    $conn = connectDB();
    $columns = [
        'is_trade_authorized' => "TINYINT(1) DEFAULT 0",
        'trade_broker_fee' => "DECIMAL(10,2) DEFAULT 0.00",
        'trade_terms' => "TEXT"
    ];
    foreach ($columns as $name => $type) {
        $check = $conn->query("SHOW COLUMNS FROM items LIKE '{$name}'");
        if ($check->num_rows == 0) {
            $conn->query("ALTER TABLE items ADD COLUMN {$name} {$type}");
        }
    }
    $conn->close();
}
setupTradeFields();
// ====================[ PAGE: SALES CUSTOMERS ]====================
if (isset($page) && $page === 'sales_customers') {
    $conn = connectDB();
    $sql = "
        SELECT 
            customer_name,
            COUNT(*) AS total_sales,
            SUM(sale_price) AS total_spent,
            SUM(commission_amount) AS total_commission,
            (
                SELECT IFNULL(SUM(r.amount), 0)
                FROM refunds r
                WHERE r.issued_credit = 1 AND r.customer_name = f.customer_name
            ) AS total_credit_issued,
            (
                SELECT IFNULL(SUM(cr.amount), 0)
                FROM credits_redeemed cr
                WHERE cr.customer_name = f.customer_name
            ) AS total_credit_redeemed
        FROM (
            SELECT buyer_name AS customer_name, sale_price, commission_amount FROM sales
            UNION ALL
            SELECT customer_name, 0 AS sale_price, 0 AS commission_amount FROM refunds WHERE issued_credit = 1
        ) AS f
        GROUP BY f.customer_name
        ORDER BY total_spent DESC
    ";
    $result = $conn->query($sql);
    $content .= "<h2 class='mt-4'>Sales Customers</h2>";
    if ($result && $result->num_rows > 0) {
        $content .= "<div class='table-responsive'><table class='table table-sm table-bordered'>
        <thead><tr>
            <th>Customer</th>
            <th>Total Sales</th>
            <th>Total Spent</th>
            <th>Total Commission</th>
            <th>Credits Issued</th>
            <th>Credits Redeemed</th>
            <th>Balance</th>
        </tr></thead><tbody>";
        while ($row = $result->fetch_assoc()) {
            $balance = $row['total_credit_issued'] - $row['total_credit_redeemed'];
            $content .= "<tr>
                <td>" . htmlspecialchars($row['customer_name']) . "</td>
                <td>" . $row['total_sales'] . "</td>
                <td>$" . number_format($row['total_spent'], 2) . "</td>
                <td>$" . number_format($row['total_commission'], 2) . "</td>
                <td>$" . number_format($row['total_credit_issued'], 2) . "</td>
                <td>$" . number_format($row['total_credit_redeemed'], 2) . "</td>
                <td>$" . number_format($balance, 2) . "</td>
            </tr>";
        }
        $content .= "</tbody></table></div>";
    } else {
        $content .= "<div class='alert alert-info'>No sales data available.</div>";
    }
    $conn->close();
}
// ====================[ PAGE: CUSTOMER CREDITS ]====================
if (isset($page) && $page === 'credits_summary') {
    $conn = connectDB();
    // Handle deletion of all credits for a customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_credit_customer'])) {
    $delete_customer = $conn->real_escape_string($_POST['delete_credit_customer']);
    // Delete from refunds table where customer and issued_credit = 1
    $conn->query("DELETE FROM refunds WHERE customer_name = '{$delete_customer}' AND issued_credit = 1");
    // Also delete redeemed credits if you want
    $conn->query("DELETE FROM credits_redeemed WHERE customer_name = '{$delete_customer}'");
}
    $sql = "
        SELECT 
            r.customer_name, 
            IFNULL(SUM(r.amount), 0) AS total_credit,
            (
                SELECT IFNULL(SUM(cr.amount), 0)
                FROM credits_redeemed cr
                WHERE cr.customer_name = r.customer_name
            ) AS total_redeemed
        FROM refunds r
        WHERE r.issued_credit = 1
        GROUP BY r.customer_name
        HAVING total_credit > 0
        ORDER BY total_credit DESC
    ";
    $result = $conn->query($sql);
    $content .= "<h2 class='mt-4'>Customer Store Credits</h2>";
    if ($result && $result->num_rows > 0) {
        $content .= "<table class='table table-sm table-bordered'>
        <thead><tr>
            <th>Customer</th>
            <th>Total Credit</th>
            <th>Redeemed</th>
            <th>Balance</th>
            <th>Action</th>
        </tr></thead><tbody>";
        while ($row = $result->fetch_assoc()) {
            $customer = htmlspecialchars($row['customer_name']);
            $credit = number_format($row['total_credit'], 2);
            $redeemed = number_format($row['total_redeemed'], 2);
            $balance = number_format($row['total_credit'] - $row['total_redeemed'], 2);
            $content .= "<tr>
                <td>{$customer}</td>
                <td>\${$credit}</td>
                <td>\${$redeemed}</td>
                <td>\${$balance}</td>
                <td>
    <a href='?action=redeem_credit&customer=" . urlencode($row['customer_name']) . "' class='btn btn-sm btn-success mb-1'>Redeem</a>
    <form method='post' action='?page=credits_summary' onsubmit='return confirm(\"Are you sure you want to delete all credit for this customer?\");' style='display:inline-block;'>
        <input type='hidden' name='delete_credit_customer' value='" . htmlspecialchars($row['customer_name']) . "'>
        <button type='submit' class='btn btn-sm btn-danger mb-1'>Delete</button>
    </form>
</td>
            </tr>";
        }
        $content .= "</tbody></table>";
    } else {
        $content .= "<div class='alert alert-info'>No customer credits recorded.</div>";
    }
    $conn->close();
}
// ====================[ PAGE: TRADE-ONLY ITEMS ]====================
if (isset($page) && $page === 'trade_items') {
    $conn = connectDB();
    $result = $conn->query("SELECT i.*, c.name AS consignor_name FROM items i LEFT JOIN consignors c ON i.consignor_id = c.id WHERE i.is_trade_authorized = 1");
    $content .= "<h2 class='mt-4'>Items Available for Trade</h2>";
    if ($result && $result->num_rows > 0) {
        $content .= "<table class='table table-sm table-bordered'><thead><tr>
            <th>Description</th><th>Make/Model</th><th>Broker Fee (%)</th><th>Terms</th><th>Consignor</th><th>Actions</th>
        </tr></thead><tbody>";
        while ($row = $result->fetch_assoc()) {
            $content .= "<tr>
                <td>" . htmlspecialchars($row['description']) . "</td>
                <td>" . htmlspecialchars($row['make_model']) . "</td>
                <td>" . number_format($row['trade_broker_fee'], 2) . "</td>
                <td>" . nl2br(htmlspecialchars($row['trade_terms'])) . "</td>
                <td>" . htmlspecialchars($row['consignor_name']) . "</td>
                <td><a href='?action=edit_item&item_id=" . $row['id'] . "' class='btn btn-sm btn-primary'>Edit Trade</a></td>
            </tr>";
        }
        $content .= "</tbody></table>";
    } else {
        $content .= "<p class='text-muted'>No items currently marked available for trade.</p>";
    }
    $conn->close();
}

// ====================[ ACTION: START RENTAL ]====================
if ($action === 'start_rental' && isset($_GET['item_id'])) {
    $item_id = (int) $_GET['item_id'];
    $conn = connectDB();
    
    // Ensure tax columns exist
    ensureTaxColumnsExist($conn);
    
    $stmt = $conn->prepare("SELECT i.*, c.name AS consignor_name, c.phone AS consignor_phone FROM items i LEFT JOIN consignors c ON i.consignor_id = c.id WHERE i.id = ? AND i.rental_authorized = 1");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $item = $result->fetch_assoc();
        $content .= "<h2 class='mt-4'>Start Rental for: " . htmlspecialchars($item['description']) . " (" . htmlspecialchars($item['make_model']) . ")";
        $content .= "<br><small>Owned by: " . htmlspecialchars($item['consignor_name']) . " - " . htmlspecialchars($item['consignor_phone']) . " | Value: $" . number_format($item['asking_price'], 2) . "</small></h2>";
        $content .= "<form method='post' action='?action=save_rental' id='rentalForm'>
            <input type='hidden' name='item_id' value='{$item_id}'>
            <input type='hidden' name='tax_rate' value='0.0825'>
            <input type='hidden' name='tax_amount' id='tax_amount_hidden' value='0.00'>
            <input type='hidden' name='total_with_tax' id='total_with_tax_hidden' value='0.00'>
            
            <div class='row'>
                <div class='col-md-6'>
                    <div class='form-group'>
                        <label>Renter Name</label>
                        <input type='text' name='renter_name' class='form-control' required>
                    </div>
                    <div class='form-group'>
                        <label>Renter Phone</label>
                        <input type='text' name='renter_phone' class='form-control'>
                    </div>
                    <div class='form-group'>
                        <label>Renter Email</label>
                        <input type='email' name='renter_email' class='form-control'>
                    </div>
                    <div class='form-group'>
                        <label>Renter Address</label>
                        <textarea name='renter_address' class='form-control'></textarea>
                    </div>
                    <div class='form-group'>
                        <label>Renter Contact</label>
                        <input type='text' name='renter_contact' class='form-control' required>
                    </div>
                </div>
                <div class='col-md-6'>
                    <div class='form-group'>
                        <label>Renter ID / License #</label>
                        <input type='text' name='renter_id' class='form-control'>
                    </div>
                    <div class='form-group'>
                        <label>License State</label>
                        <input type='text' name='license_state' class='form-control' maxlength='2'>
                    </div>
                    <div class='form-group'>
                        <label>Daily Rate ($)</label>
                        <input type='number' name='daily_rate' id='daily_rate' step='0.01' class='form-control' required>
                    </div>
                    <div class='form-group'>
                        <label>Start Date</label>
                        <input type='date' name='rental_start' id='rental_start' class='form-control' required value='" . date('Y-m-d') . "'>
                    </div>
                    <div class='form-group'>
                        <label>End Date</label>
                        <input type='date' name='rental_end' id='rental_end' class='form-control' required>
                    </div>
                </div>
            </div>
            
            <div class='card mt-4 mb-4'>
                <div class='card-header'>
                    <h5>Rental Summary</h5>
                </div>
                <div class='card-body'>
                    <div class='row'>
                        <div class='col-md-6'>
                            <div class='form-group'>
                                <label>Number of Days</label>
                                <input type='text' id='num_days' class='form-control' readonly>
                            </div>
                            <div class='form-group'>
                                <label>Subtotal</label>
                                <div class='input-group'>
                                    <div class='input-group-prepend'>
                                        <span class='input-group-text'>$</span>
                                    </div>
                                    <input type='text' id='subtotal' class='form-control' readonly>
                                </div>
                            </div>
                        </div>
                        <div class='col-md-6'>
                            <div class='form-group'>
                                <label>Sales Tax (8.25%)</label>
                                <div class='input-group'>
                                    <div class='input-group-prepend'>
                                        <span class='input-group-text'>$</span>
                                    </div>
                                    <input type='text' id='tax_amount' class='form-control' readonly>
                                </div>
                            </div>
                            <div class='form-group'>
                                <label>Total Amount</label>
                                <div class='input-group'>
                                    <div class='input-group-prepend'>
                                        <span class='input-group-text'>$</span>
                                    </div>
                                    <input type='text' id='total_amount' class='form-control' readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class='form-group mt-3'>
                        <label>Security Deposit ($)</label>
                        <input type='number' name='deposit' id='deposit' step='0.01' class='form-control' value='0.00'>
                    </div>
                </div>
            </div>
            
            <div class='form-group'>
                <label>Additional Notes</label>
                <textarea name='notes' class='form-control'></textarea>
            </div>
            
            <button type='submit' class='btn btn-success btn-lg mt-3'>Save Rental</button>
        </form>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dailyRateInput = document.getElementById('daily_rate');
            const startDateInput = document.getElementById('rental_start');
            const endDateInput = document.getElementById('rental_end');
            const numDaysDisplay = document.getElementById('num_days');
            const subtotalDisplay = document.getElementById('subtotal');
            const taxAmountDisplay = document.getElementById('tax_amount');
            const taxAmountHidden = document.getElementById('tax_amount_hidden');
            const totalAmountDisplay = document.getElementById('total_amount');
            const totalWithTaxHidden = document.getElementById('total_with_tax_hidden');
            const taxRate = 0.0825; // 8.25%
            
            function calculateDays() {
                if (!startDateInput.value || !endDateInput.value) return 0;
                
                const startDate = new Date(startDateInput.value);
                const endDate = new Date(endDateInput.value);
                
                // Make sure end date is not before start date
                if (endDate < startDate) {
                    endDateInput.value = startDateInput.value;
                    return 1;
                }
                
                // Calculate the difference in days
                const diffTime = Math.abs(endDate - startDate);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // +1 to include the start day
                
                return diffDays;
            }
            
            function updateTotals() {
                const dailyRate = parseFloat(dailyRateInput.value) || 0;
                const days = calculateDays();
                
                numDaysDisplay.value = days;
                
                const subtotal = dailyRate * days;
                subtotalDisplay.value = subtotal.toFixed(2);
                
                const taxAmount = subtotal * taxRate;
                taxAmountDisplay.value = taxAmount.toFixed(2);
                taxAmountHidden.value = taxAmount.toFixed(2);
                
                const totalAmount = subtotal + taxAmount;
                totalAmountDisplay.value = totalAmount.toFixed(2);
                totalWithTaxHidden.value = totalAmount.toFixed(2);
            }
            
            dailyRateInput.addEventListener('input', updateTotals);
            startDateInput.addEventListener('change', updateTotals);
            endDateInput.addEventListener('change', updateTotals);
            
            // Set default end date to one day after start date
            if (startDateInput.value && !endDateInput.value) {
                const startDate = new Date(startDateInput.value);
                startDate.setDate(startDate.getDate() + 1);
                endDateInput.value = startDate.toISOString().split('T')[0];
            }
            
            updateTotals(); // Initialize on page load
        });
        </script>";
    } else {
        $content .= "<div class='alert alert-danger'>Item not authorized for rental or not found.</div>";
    }
    $conn->close();
}

// ====================[ ACTION: SAVE RENTAL ]====================
if ($action === 'save_rental' && isset($_POST['item_id'])) {
    $conn = connectDB();
    
    // Ensure tax columns exist
    ensureTaxColumnsExist($conn);
    
    $item_id = (int) $_POST['item_id'];
    
    // Calculate dates and rental days
    $rental_start = $_POST['rental_start'];
    $rental_end = $_POST['rental_end'];
    
    $start_date = new DateTime($rental_start);
    $end_date = new DateTime($rental_end);
    $days_diff = $end_date->diff($start_date)->days + 1; // Include start day
    
    // Calculate rental amounts
    $daily_rate = floatval($_POST['daily_rate']);
    $deposit = isset($_POST['deposit']) ? floatval($_POST['deposit']) : 0;
    $subtotal = $daily_rate * $days_diff;
    
    // Tax calculations
    $tax_rate = 0.0825; // 8.25%
    $tax_amount = $subtotal * $tax_rate;
    $total_with_tax = $subtotal + $tax_amount;
    
    // Prepare contact info
    $renter_name = $_POST['renter_name'];
    $renter_contact = $_POST['renter_contact'];
    $renter_phone = isset($_POST['renter_phone']) ? $_POST['renter_phone'] : '';
    $renter_email = isset($_POST['renter_email']) ? $_POST['renter_email'] : '';
    $renter_address = isset($_POST['renter_address']) ? $_POST['renter_address'] : '';
    $renter_id = isset($_POST['renter_id']) ? $_POST['renter_id'] : '';
    $license_state = isset($_POST['license_state']) ? $_POST['license_state'] : '';
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
    
    // Insert rental record
    $stmt = $conn->prepare("INSERT INTO rentals (
        item_id, renter_name, renter_contact, renter_phone, renter_email, 
        renter_address, renter_id, license_state, rental_start, rental_end, 
        total_amount, rental_fee, daily_rate, number_of_days, deposit, 
        tax_rate, tax_amount, notes, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $status = 'active';
    
    $stmt->bind_param("isssssssssdddiidss", 
        $item_id, $renter_name, $renter_contact, $renter_phone, $renter_email, 
        $renter_address, $renter_id, $license_state, $rental_start, $rental_end, 
        $total_with_tax, $subtotal, $daily_rate, $days_diff, $deposit, 
        $tax_rate, $tax_amount, $notes, $status
    );
    
    if ($stmt->execute()) {
        $rental_id = $conn->insert_id;
        
        // Update item status to rented
        $conn->query("UPDATE items SET status = 'rented', rental_authorized = 0 WHERE id = {$item_id}");
        
        $content .= "<div class='alert alert-success'>
            <h4>Rental Created Successfully!</h4>
            <p>Rental #" . str_pad($rental_id, 5, '0', STR_PAD_LEFT) . " has been created for {$renter_name}.</p>
            <p><strong>Item:</strong> " . htmlspecialchars($_POST['item_description'] ?? '') . "</p>
            <p><strong>Duration:</strong> {$days_diff} days (" . date('m/d/Y', strtotime($rental_start)) . " to " . date('m/d/Y', strtotime($rental_end)) . ")</p>
            <p><strong>Subtotal:</strong> $" . number_format($subtotal, 2) . "</p>
            <p><strong>Sales Tax (8.25%):</strong> $" . number_format($tax_amount, 2) . "</p>
            <p><strong>Total Amount:</strong> $" . number_format($total_with_tax, 2) . "</p>";
        
        if ($deposit > 0) {
            $content .= "<p><strong>Security Deposit:</strong> $" . number_format($deposit, 2) . "</p>";
        }
        
        $content .= "<div class='mt-3'>
                <a href='?page=print_rental_agreement&rental_id={$rental_id}' class='btn btn-info' target='_blank'>Print Rental Agreement</a>
                <a href='?page=rentals' class='btn btn-secondary'>View All Rentals</a>
            </div>
        </div>";
    } else {
        $content .= "<div class='alert alert-danger'>Error creating rental: " . $stmt->error . "</div>";
    }
    
    $stmt->close();
    $conn->close();
}

// ====================[ ACTION: START TRADE ]====================
if (isset($action) && $action === 'start_trade' && isset($_GET['item_id'])) {
    $conn = connectDB();
    $item_id = (int) $_GET['item_id'];
    $is_edit = isset($_GET['edit']) && isset($_GET['trade_id']);
    $existing = null;

    if ($is_edit) {
        $trade_id = (int) $_GET['trade_id'];
        $stmt = $conn->prepare("SELECT * FROM trades WHERE id = ?");
        $stmt->bind_param("i", $trade_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $existing = $result->fetch_assoc();
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT i.*, c.name AS consignor_name, c.phone FROM items i LEFT JOIN consignors c ON i.consignor_id = c.id WHERE i.id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();

    $stmt->close();
    $conn->close();

    if ($item) {
        $content = ''; // start fresh to avoid leftovers
        $content .= "<h2 class='mt-4'>" . ($is_edit ? "Edit Trade" : "Start Trade") . " for Item</h2>";
        $content .= "<div class='mb-3'>";
        $content .= "<strong>Item:</strong> " . htmlspecialchars($item['description']) . " (" . htmlspecialchars($item['make_model']) . ")<br>";
        $content .= "<strong>Owned by:</strong> " . htmlspecialchars($item['consignor_name']) . " (" . htmlspecialchars($item['phone']) . ")<br>";
        $content .= "<strong>Original Asking Price:</strong> $" . number_format($item['asking_price'], 2);
        $content .= "</div>";

        $content .= "<form method='post' action='?action=" . ($is_edit ? "update_trade" : "save_trade") . "'>";
        if ($is_edit) {
            $content .= "<input type='hidden' name='trade_id' value='" . $trade_id . "'>";
        }
        $content .= "<input type='hidden' name='item_id' value='" . $item_id . "'>";

        $content .= "<div class='form-group'><label>Traded For:</label><input type='text' name='trade_for' class='form-control' value='" . htmlspecialchars($existing['trade_for'] ?? '') . "'></div>";
        $prefill_trade_value = htmlspecialchars($existing['trade_value'] ?? $item['asking_price'] ?? '');
        $content .= "<div class='form-group'><label>Trade Value ($):</label><input type='number' step='0.01' name='trade_value' id='trade_value' class='form-control' value='{$prefill_trade_value}'></div>";

        $content .= "<div class='form-group'><label>Cash Difference ($):</label><input type='number' step='0.01' name='cash_difference' id='cash_difference' class='form-control' value='" . htmlspecialchars($existing['cash_difference'] ?? '') . "'></div>";

        $content .= "<div class='form-group'><label>Broker Fee (%):</label><input type='number' step='0.01' name='broker_fee' id='broker_fee' class='form-control' value='" . htmlspecialchars($existing['broker_fee'] ?? '0') . "'></div>";
        $content .= "<div class='form-check mb-3'><input type='checkbox' class='form-check-input' id='override_broker_fee'><label class='form-check-label' for='override_broker_fee'>Manually Override Broker Fee</label></div>";
// Add this after the broker fee field in your start_trade action
$content .= "<div class='form-group'><label>Delivery/Pickup Fee ($):</label><input type='number' step='0.01' name='delivery_fee' id='delivery_fee' class='form-control' value='" . htmlspecialchars($existing['delivery_fee'] ?? '0.00') . "'></div>";
        // Preview card
        
// Update the Preview card to include delivery fee
$content .= "
<div class='card mt-3'>
    <div class='card-header'>Trade Preview</div>
    <div class='card-body'>
        <p><strong>Cash Difference:</strong> <span id='preview-cash-diff'>\$0.00</span></p>
        <p><strong>Estimated Broker Fee:</strong> <span id='preview-broker-fee'>\$0.00</span></p>
        <p><strong>Delivery/Pickup Fee:</strong> <span id='preview-delivery-fee'>\$0.00</span></p>
        <small class='text-muted'>Broker fee is based on trade value unless override is selected.</small>
    </div>
</div>";

        $content .= "<div class='form-group'><label>Trade Notes:</label><textarea name='trade_notes' class='form-control'>" . htmlspecialchars($existing['trade_notes'] ?? '') . "</textarea></div>";

        $content .= "<h4>Trader Information</h4>";
        $content .= "<div class='form-group'><label>Trader Name:</label><input type='text' name='trader_name' class='form-control' value='" . htmlspecialchars($existing['trader_name'] ?? '') . "'></div>";
        $content .= "<div class='form-group'><label>Phone:</label><input type='text' name='trader_phone' class='form-control' value='" . htmlspecialchars($existing['trader_phone'] ?? '') . "'></div>";
        $content .= "<div class='form-group'><label>Email:</label><input type='email' name='trader_email' class='form-control' value='" . htmlspecialchars($existing['trader_email'] ?? '') . "'></div>";
        $content .= "<div class='form-group'><label>Address:</label><textarea name='trader_address' class='form-control'>" . htmlspecialchars($existing['trader_address'] ?? '') . "</textarea></div>";
        $content .= "<div class='form-group'><label>License/ID:</label><input type='text' name='trader_id' class='form-control' value='" . htmlspecialchars($existing['trader_id'] ?? '') . "'></div>";
        $content .= "<div class='form-group'><label>License State:</label><input type='text' name='license_state' class='form-control' value='" . htmlspecialchars($existing['license_state'] ?? '') . "'></div>";

        $content .= "<div class='form-group'><label>Status:</label><select name='status' class='form-control'>";
        $statuses = ['active', 'reacquired', 'void'];
        $selected_status = $existing['status'] ?? 'active';
        foreach ($statuses as $status) {
            $sel = ($selected_status === $status) ? 'selected' : '';
            $content .= "<option value='{$status}' {$sel}>" . ucfirst($status) . "</option>";
        }
        $content .= "</select></div>";

        $button_text = $is_edit ? "Update Contract" : "Save and Generate Contract";
        $content .= "<button type='submit' class='btn btn-success'>{$button_text}</button> ";
        $content .= "<a href='?page=inventory' class='btn btn-secondary'>Cancel</a>";
        $content .= "</form>";

        $jsAskingPrice = isset($item['asking_price']) ? (float)$item['asking_price'] : 0;
$content .= "<script>
document.addEventListener('DOMContentLoaded', function () {
    const tradeValue = document.getElementById('trade_value');
    const brokerFee = document.getElementById('broker_fee');
    const cashDifference = document.getElementById('cash_difference');
    const deliveryFee = document.getElementById('delivery_fee');  // Add this line
    const overrideCheckbox = document.getElementById('override_broker_fee');
    const previewCash = document.getElementById('preview-cash-diff');
    const previewFee = document.getElementById('preview-broker-fee');
    const previewDeliveryFee = document.getElementById('preview-delivery-fee');  // Add this line
    const askingPrice = {$jsAskingPrice};

    function updatePreview() {
        const trade = parseFloat(tradeValue.value) || 0;
        const rate = parseFloat(brokerFee.value) || 0;
        const delivery = parseFloat(deliveryFee.value) || 0;  // Add this line
        let suggested = 0;

        if (!overrideCheckbox.checked) {
            if (trade <= 500) suggested = 50;
            else if (trade <= 1000) suggested = 100;
            else if (trade <= 2000) suggested = 150;
            else suggested = 200;

            brokerFee.value = ((suggested / trade) * 100).toFixed(2);
            previewFee.innerText = '$' + suggested.toFixed(2);
        } else {
            const fee = (trade * rate / 100).toFixed(2);
            previewFee.innerText = '$' + fee;
        }

        const difference = askingPrice - trade;
        cashDifference.value = difference.toFixed(2);
        previewCash.innerText = '$' + difference.toFixed(2);
        
        // Update delivery fee preview
        previewDeliveryFee.innerText = '$' + delivery.toFixed(2);
    }

    [tradeValue, brokerFee, cashDifference, deliveryFee, overrideCheckbox].forEach(el => {  // Add deliveryFee to the array
        if (el) { // Check that element exists before adding listener
            el.addEventListener('input', updatePreview);
        }
    });

    updatePreview();
});
</script>";

       echo displayPage($content);
        exit;
    } else {
        echo displayPage("<div class='alert alert-danger'>Item not found.</div>");
        exit;
    }
}

// ====================[ ACTION: SAVE TRADE ]====================

if ($action === 'save_trade' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add this debug code
    error_log("SAVE_TRADE action triggered with POST data: " . print_r($_POST, true));
    
    $conn = connectDB();
    // rest of your code
    $conn = connectDB();
    $item_id = (int)$_POST['item_id'];
    $trade_for = $_POST['trade_for'];
    $trade_value = $_POST['trade_value'];
    $broker_fee = $_POST['broker_fee'];
    $delivery_fee = isset($_POST['delivery_fee']) ? (float)$_POST['delivery_fee'] : 0; // Add delivery fee
    $trade_notes = $_POST['trade_notes'];
    $trader_name = $_POST['trader_name'];
    $trader_phone = $_POST['trader_phone'];
    $trader_email = $_POST['trader_email'];
    $trader_address = $_POST['trader_address'];
    $trader_id = $_POST['trader_id'];
    $license_state = $_POST['license_state'];
    
    // Update SQL to include delivery_fee field
    $stmt = $conn->prepare("INSERT INTO trades (
        item_id, trade_for, trade_value, broker_fee, delivery_fee, trade_notes, 
        trader_name, trader_phone, trader_email, trader_address, trader_id, 
        license_state, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    
    if ($stmt === false) {
        die("<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>");
    }
    
    // Update bind_param to include delivery_fee as a double (d)
    $stmt->bind_param("isdddsssssss", 
        $item_id, $trade_for, $trade_value, $broker_fee, $delivery_fee, $trade_notes, 
        $trader_name, $trader_phone, $trader_email, $trader_address, $trader_id, 
        $license_state
    );
    
    if ($stmt->execute()) {
        // Mark item as traded - ensure the status is properly set
        $update = $conn->prepare("UPDATE items SET status = 'traded' WHERE id = ?");
        $update->bind_param("i", $item_id);
        $result = $update->execute();
        if (!$result) {
            $content .= "<div class='alert alert-warning'>Trade saved but failed to update item status: " . $conn->error . "</div>";
            // Force update with direct query if prepared statement failed
            $conn->query("UPDATE items SET status = 'traded' WHERE id = $item_id");
        }
        $update->close();
        
        // Double-check the status was updated
        $check = $conn->prepare("SELECT status FROM items WHERE id = ?");
        $check->bind_param("i", $item_id);
        $check->execute();
        $check_result = $check->get_result();
        if ($row = $check_result->fetch_assoc()) {
            if ($row['status'] !== 'traded') {
                // Force update with direct query if status still not updated
                $conn->query("UPDATE items SET status = 'traded' WHERE id = $item_id");
            }
        }
        $check->close();
        
        $new_trade_id = $conn->insert_id;
        header("Location: ?action=trade_contract&trade_id={$new_trade_id}");
        exit;
    } else {
        // Echo the error immediately so it's visible to the user
        echo "<div class='alert alert-danger'>Error saving trade: " . $stmt->error . "</div>";
        echo "<a href='javascript:history.back()' class='btn btn-primary'>Go Back</a>";
        $stmt->close();
        $conn->close();
        exit; // Stop execution to prevent further processing
    }
    $stmt->close();
    $conn->close();
}

// ====================[ ACTION: UPDATE TRADE ]====================
if ($action === 'update_trade' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = connectDB();
    
    $trade_id = (int)$_POST['trade_id'];
    $item_id = (int)$_POST['item_id'];
    $trade_for = $_POST['trade_for'];
    $trade_value = $_POST['trade_value'];
    $broker_fee = $_POST['broker_fee'];
    $delivery_fee = $_POST['delivery_fee'] ?? 0;  // Add this line
    $trade_notes = $_POST['trade_notes'];
    $trader_name = $_POST['trader_name'];
    $trader_phone = $_POST['trader_phone'];
    $trader_email = $_POST['trader_email'];
    $trader_address = $_POST['trader_address'];
    $trader_id = $_POST['trader_id'];
    $license_state = $_POST['license_state'];
    
    $stmt = $conn->prepare("UPDATE trades SET 
        item_id = ?, 
        trade_for = ?, 
        trade_value = ?, 
        broker_fee = ?, 
        delivery_fee = ?,  /* Add this line */
        trade_notes = ?, 
        trader_name = ?, 
        trader_phone = ?, 
        trader_email = ?, 
        trader_address = ?, 
        trader_id = ?, 
        license_state = ? 
        WHERE id = ?");
    
    if ($stmt === false) {
        die("<div class='alert alert-danger'>Prepare failed: " . $conn->error . "</div>");
    }
    
    $stmt->bind_param(
        "isddssssssssi",  // Updated type string to include delivery_fee as 'd'
        $item_id, $trade_for, $trade_value, $broker_fee, $delivery_fee, $trade_notes, $trader_name,
        $trader_phone, $trader_email, $trader_address, $trader_id, $license_state, $trade_id
    );
    
    if ($stmt->execute()) {
        // Make sure the item status is set to traded
        $update = $conn->prepare("UPDATE items SET status = 'traded' WHERE id = ?");
        $update->bind_param("i", $item_id);
        $update->execute();
        $update->close();
        
        header("Location: ?action=trade_contract&trade_id={$trade_id}");
        exit;
    } else {
        $content .= "<div class='alert alert-danger'>Error updating trade: " . $conn->error . "</div>";
    }
    
    $stmt->close();
    $conn->close();
}


// ====================[ ACTION: TRADE CONTRACT ]====================
if ($action === 'trade_contract' && isset($_GET['trade_id'])) {
    $trade_id = (int) $_GET['trade_id'];
    $conn = connectDB();

    $stmt = $conn->prepare("SELECT t.*, i.description, i.make_model, i.asking_price, c.name AS consignor_name, c.phone AS consignor_phone 
                            FROM trades t 
                            JOIN items i ON t.item_id = i.id 
                            LEFT JOIN consignors c ON i.consignor_id = c.id 
                            WHERE t.id = ?");
    $stmt->bind_param("i", $trade_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $content = ''; // initialize

    if ($result && $result->num_rows > 0) {
        $trade = $result->fetch_assoc();
        $contract_id = "T-" . str_pad($trade['id'], 5, '0', STR_PAD_LEFT);
        $item_value = number_format($trade['asking_price'], 2);
        $trade_value = number_format($trade['trade_value'], 2);
        $broker_fee_amt = $trade['trade_value'] * ($trade['broker_fee'] / 100);
        $broker_fee_display = number_format($broker_fee_amt, 2);
        $delivery_fee_display = number_format($trade['delivery_fee'], 2);
        $net_value = number_format($trade['trade_value'] - $broker_fee_amt - $trade['delivery_fee'], 2);

        $content .= "<title>Trade Contract #{$contract_id}</title>";
        $content .= "<div class='contract printable'>
        <h2 class='mt-4'>Trade Agreement - Contract #{$contract_id}</h2>
        <p><strong>Trade Date:</strong> " . date('F j, Y', strtotime($trade['created_at'])) . "</p>
        <p><strong>Description:</strong> " . htmlspecialchars($trade['description']) . "<br>
        <strong>Model:</strong> " . htmlspecialchars($trade['make_model']) . "<br>
        <strong>Listed Value:</strong> \${$item_value}</p>
        <p><strong>Traded For:</strong> " . htmlspecialchars($trade['trade_for']) . "<br>
        <strong>Reported Trade Value:</strong> \${$trade_value}<br>
        <strong>Broker Fee:</strong> {$trade['broker_fee']}% (\${$broker_fee_display})<br>
        <strong>Delivery/Pickup Fee:</strong> \${$delivery_fee_display}<br>
        <strong>Net Trade Value:</strong> \${$net_value}</p>

        <p><strong>Trader Name:</strong> " . htmlspecialchars($trade['trader_name']) . "<br>
        <strong>Phone:</strong> " . htmlspecialchars($trade['trader_phone']) . "<br>
        <strong>Email:</strong> " . htmlspecialchars($trade['trader_email']) . "<br>
        <strong>Address:</strong> " . nl2br(htmlspecialchars($trade['trader_address'])) . "<br>
        <strong>License/ID:</strong> " . htmlspecialchars($trade['trader_id']) . " (" . htmlspecialchars($trade['license_state']) . ")</p>

        <h4>Terms</h4>
        <div class='terms'>
            <p>Back2Work Equipment is not responsible for any defects, damages, or disputes regarding the traded item. All trades are final and are made between the original consignor and the trader. We do not inspect, guarantee, or warrant the functionality, safety, or condition of any equipment.</p>
            <p>All trades are final. No returns, refunds, or exchanges will be accepted for any reason. Back2Work Equipment is not responsible for defects, title issues, or performance of traded items. All trades are made “as-is” and “where-is,” without warranties of any kind.</p>
            <p>The trader affirms ownership or full legal authority to trade the item and agrees to hold Back2Work Equipment harmless from any disputes, damages, or claims resulting from the transaction.</p>
            <p>The Consignor affirms they are the rightful owner of the item and that it is free from any liens, security interests, or claims. The Consignor certifies that the information provided above is accurate to the best of their knowledge. No formal title exists for the item unless otherwise disclosed. The buyer/trader agrees to indemnify Back2Work Equipment against any ownership disputes or claims.</p>
            <p>Both parties agree to the above trade and affirm the accuracy of the information provided.</p>
        </div>

        <table>
            <tr><td><strong>Trader Signature/Date:</strong><br><br></td></tr>
            <tr><td><br><br></td></tr>
            <tr><td><strong>Back2Work Equipment Representative Signature/Date:</strong><br><br></td></tr>
        </table>

        <div class='d-print-none mt-3'>
            <button onclick='window.print()' class='btn btn-warning'>Print Contract</button>
            <a href='?action=start_trade&item_id={$trade['item_id']}&edit=1&trade_id={$trade['id']}' class='btn btn-warning ml-2'>Edit Trade</a>
        </div>
        </div>

        <style>
        @media print {
            body * {
                visibility: hidden !important;
            }
            .printable, .printable * {
                visibility: visible !important;
            }
            .printable {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                background: white;
                padding: 1in;
                box-sizing: border-box;
                font-family: sans-serif;
            }
            .d-print-none {
                display: none !important;
            }
        }
        </style>";
    } else {
        $content .= "<div class='alert alert-danger'>Trade record not found.</div>";
    }

    // Inside your trade receipt generation code

// Add disclosures section
if (!empty($item['known_issues']) || !empty($item['wear_description']) || !empty($item['hours_used']) || !empty($item['maintenance_history'])) {
    echo "<div style='margin-top: 20px; border: 1px solid #333; padding: 15px;'>";
    echo "<h4 style='border-bottom: 1px solid #333; padding-bottom: 5px;'>Equipment Condition & Disclosures</h4>";
    
    echo "<table style='width: 100%; margin-top: 10px;'>";
    
    if (!empty($item['hours_used'])) {
        echo "<tr><td style='font-weight:bold; width: 30%;'>Hours Used:</td><td>{$item['hours_used']} hours</td></tr>";
    }
    
    if (!empty($item['condition_desc'])) {
        echo "<tr><td style='font-weight:bold; vertical-align:top; width: 30%;'>General Condition:</td><td>" . nl2br(htmlspecialchars($item['condition_desc'])) . "</td></tr>";
    }
    
    if (!empty($item['known_issues'])) {
        echo "<tr><td style='font-weight:bold; vertical-align:top; width: 30%;'>Known Issues:</td><td>" . nl2br(htmlspecialchars($item['known_issues'])) . "</td></tr>";
    }
    
    if (!empty($item['wear_description'])) {
        echo "<tr><td style='font-weight:bold; vertical-align:top; width: 30%;'>Signs of Wear:</td><td>" . nl2br(htmlspecialchars($item['wear_description'])) . "</td></tr>";
    }
    
    if (!empty($item['maintenance_history'])) {
        echo "<tr><td style='font-weight:bold; vertical-align:top; width: 30%;'>Maintenance History:</td><td>" . nl2br(htmlspecialchars($item['maintenance_history'])) . "</td></tr>";
    }
    
    echo "</table>";
    
    echo "<p style='margin-top: 15px; font-style: italic;'>Trader acknowledges that they have received and reviewed all disclosures about this equipment's condition and accepts the equipment in its current condition.</p>";
    
    echo "</div>"; // End disclosures div
}

    $stmt->close();
    $conn->close();

    echo displayPage($content);
    exit;
}


// ====================[ PAGE: BROKER EARNINGS SUMMARY ]====================
if ($page === 'broker_earnings') {
    $conn = connectDB();
    
    // Add year selector
    $earliest_year_query = "SELECT MIN(YEAR(created_at)) AS min_year FROM trades";
    $earliest_result = $conn->query($earliest_year_query);
    $earliest_year = date('Y'); // Default to current year
    
    if ($earliest_result && $earliest_result->num_rows > 0) {
        $row = $earliest_result->fetch_assoc();
        if (!empty($row['min_year'])) {
            $earliest_year = $row['min_year'];
        }
    }
    
    // Check if rental data might start earlier
    $earliest_rental_query = "SELECT MIN(YEAR(rental_start)) AS min_year FROM rentals";
    $earliest_rental_result = $conn->query($earliest_rental_query);
    if ($earliest_rental_result && $earliest_rental_result->num_rows > 0) {
        $row = $earliest_rental_result->fetch_assoc();
        if (!empty($row['min_year']) && $row['min_year'] < $earliest_year) {
            $earliest_year = $row['min_year'];
        }
    }
    
    // Get the selected year (default to current year if not specified)
    $selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    
    // Generate year options from earliest year to current year
    $year_options = '';
    for ($year = date('Y'); $year >= $earliest_year; $year--) {
        $selected = ($year == $selected_year) ? 'selected' : '';
        $year_options .= "<option value='{$year}' {$selected}>{$year}</option>";
    }
    
    $content .= "
    <h2 class='mt-4'>Revenue Summary for {$selected_year}</h2>
    <div class='mb-4'>
        <form method='get' class='form-inline'>
            <input type='hidden' name='page' value='broker_earnings'>
            <div class='form-group'>
                <label for='year'>Select Year: </label>
                <select name='year' id='year' class='form-control ml-2' onchange='this.form.submit()'>
                    {$year_options}
                </select>
            </div>
        </form>
    </div>";
    
    // Get broker and trade earnings (monthly) for the selected year
    $sql_trades = "
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') AS month,
            SUM(trade_value * (broker_fee / 100)) AS broker_earnings,
            SUM(delivery_fee) AS delivery_earnings,
            SUM(trade_value * (broker_fee / 100) + delivery_fee) AS total_earnings,
            COUNT(*) AS trades
        FROM trades
        WHERE YEAR(created_at) = {$selected_year}
        GROUP BY month
        ORDER BY month
    ";
    $result_trades = $conn->query($sql_trades);
    
    // Get rental earnings (monthly) for the selected year
    $sql_rentals = "
        SELECT 
            DATE_FORMAT(rental_start, '%Y-%m') AS month,
            SUM(total_amount) AS rental_earnings,
            SUM(deposit - IFNULL(deposit_returned, 0)) AS retained_deposits,
            COUNT(*) AS rental_count
        FROM rentals
        WHERE YEAR(rental_start) = {$selected_year}
        GROUP BY month
        ORDER BY month
    ";
    $result_rentals = $conn->query($sql_rentals);
    
    // Get annual totals for the selected year
    $sql_annual_trades = "
        SELECT 
            SUM(trade_value * (broker_fee / 100)) AS annual_broker_earnings,
            SUM(delivery_fee) AS annual_delivery_earnings,
            SUM(trade_value * (broker_fee / 100) + delivery_fee) AS annual_trade_total,
            COUNT(*) AS annual_trades
        FROM trades
        WHERE YEAR(created_at) = {$selected_year}
    ";
    $result_annual_trades = $conn->query($sql_annual_trades);
    $annual_trades = $result_annual_trades->fetch_assoc();
    
    $sql_annual_rentals = "
        SELECT 
            SUM(total_amount) AS annual_rental_earnings,
            SUM(deposit - IFNULL(deposit_returned, 0)) AS annual_retained_deposits,
            COUNT(*) AS annual_rental_count
        FROM rentals
        WHERE YEAR(rental_start) = {$selected_year}
    ";
    $result_annual_rentals = $conn->query($sql_annual_rentals);
    $annual_rentals = $result_annual_rentals->fetch_assoc();
    
    // Calculate annual totals
    $annual_broker_earnings = floatval($annual_trades['annual_broker_earnings'] ?? 0);
    $annual_delivery_earnings = floatval($annual_trades['annual_delivery_earnings'] ?? 0);
    $annual_trade_total = floatval($annual_trades['annual_trade_total'] ?? 0);
    $annual_trades_count = intval($annual_trades['annual_trades'] ?? 0);
    $annual_rental_earnings = floatval($annual_rentals['annual_rental_earnings'] ?? 0);
    $annual_retained_deposits = floatval($annual_rentals['annual_retained_deposits'] ?? 0);
    $annual_rental_count = intval($annual_rentals['annual_rental_count'] ?? 0);
    $annual_total = $annual_trade_total + $annual_rental_earnings + $annual_retained_deposits;
    
    // Show Annual Summary
    $content .= "
    <div class='card mb-4'>
        <div class='card-header bg-primary text-white'>
            <h3 class='card-title mb-0'>{$selected_year} Annual Summary</h3>
        </div>
        <div class='card-body'>
            <div class='row'>
                <div class='col-md-4'>
                    <h5>Trade Revenue</h5>
                    <ul class='list-group list-group-flush'>
                        <li class='list-group-item'>Total Trades: <strong>{$annual_trades_count}</strong></li>
                        <li class='list-group-item'>Broker Fees: <strong>$" . number_format($annual_broker_earnings, 2) . "</strong></li>
                        <li class='list-group-item'>Delivery Fees: <strong>$" . number_format($annual_delivery_earnings, 2) . "</strong></li>
                        <li class='list-group-item'>Trade Subtotal: <strong>$" . number_format($annual_trade_total, 2) . "</strong></li>
                    </ul>
                </div>
                <div class='col-md-4'>
                    <h5>Rental Revenue</h5>
                    <ul class='list-group list-group-flush'>
                        <li class='list-group-item'>Total Rentals: <strong>{$annual_rental_count}</strong></li>
                        <li class='list-group-item'>Rental Fees: <strong>$" . number_format($annual_rental_earnings, 2) . "</strong></li>
                        <li class='list-group-item'>Retained Deposits: <strong>$" . number_format($annual_retained_deposits, 2) . "</strong></li>
                        <li class='list-group-item'>Rental Subtotal: <strong>$" . number_format($annual_rental_earnings + $annual_retained_deposits, 2) . "</strong></li>
                    </ul>
                </div>
                <div class='col-md-4'>
                    <h5>Total Revenue</h5>
                    <ul class='list-group list-group-flush'>
                        <li class='list-group-item bg-light'><strong>{$selected_year} TOTAL REVENUE: $" . number_format($annual_total, 2) . "</strong></li>
                        <li class='list-group-item'>Transactions: <strong>" . ($annual_trades_count + $annual_rental_count) . "</strong></li>
                        <li class='list-group-item'>Monthly Average: <strong>$" . number_format($annual_total / 12, 2) . "</strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>";
    
    // Combine the data from both queries
    $months = [];
    
    // Process trade data
    if ($result_trades && $result_trades->num_rows > 0) {
        while ($row = $result_trades->fetch_assoc()) {
            $month = $row['month'];
            $months[$month] = [
                'month' => $month,
                'trades' => (int)$row['trades'],
                'broker_earnings' => floatval($row['broker_earnings']),
                'delivery_earnings' => floatval($row['delivery_earnings']),
                'trade_total' => floatval($row['total_earnings']),
                'rental_count' => 0,
                'rental_earnings' => 0,
                'retained_deposits' => 0
            ];
        }
    }
    
    // Process rental data and merge with trade data
    if ($result_rentals && $result_rentals->num_rows > 0) {
        while ($row = $result_rentals->fetch_assoc()) {
            $month = $row['month'];
            if (isset($months[$month])) {
                // Update existing month data
                $months[$month]['rental_count'] = (int)$row['rental_count'];
                $months[$month]['rental_earnings'] = floatval($row['rental_earnings']);
                $months[$month]['retained_deposits'] = floatval($row['retained_deposits']);
            } else {
                // Create new month entry if it doesn't exist from trades
                $months[$month] = [
                    'month' => $month,
                    'trades' => 0,
                    'broker_earnings' => 0,
                    'delivery_earnings' => 0,
                    'trade_total' => 0,
                    'rental_count' => (int)$row['rental_count'],
                    'rental_earnings' => floatval($row['rental_earnings']),
                    'retained_deposits' => floatval($row['retained_deposits'])
                ];
            }
        }
    }
    
    // Sort by month (ascending for charts, descending for table)
    ksort($months); // First sort in ascending order for charts
    $months_for_charts = $months; // Save a copy for charts
    krsort($months); // Then sort in descending order for display table
    
    $content .= "<h3 class='mt-5 mb-3'>Monthly Breakdown</h3>";
    
    // Update the download button for direct download (no AJAX)
    $content .= "<a href='index.php?action=download_earnings_csv&year={$selected_year}' class='btn btn-primary mb-4'>Download CSV Report</a>";

    
    if (count($months) > 0) {
        $grand_total_trades = 0;
        $grand_total_broker = 0;
        $grand_total_delivery = 0;
        $grand_total_rentals = 0;
        $grand_total_deposits = 0;
        $grand_total_all = 0;
        
        $content .= "<div class='table-responsive'>
            <table class='table table-sm table-bordered'>
            <thead><tr>
                <th>Month</th>
                <th colspan='4' class='text-center bg-light'>Trade Revenue</th>
                <th colspan='3' class='text-center bg-light'>Rental Revenue</th>
                <th>Month Total</th>
            </tr>
            <tr>
                <th></th>
                <th># Trades</th>
                <th>Broker Fees</th>
                <th>Delivery Fees</th>
                <th>Trade Subtotal</th>
                <th># Rentals</th>
                <th>Rental Fees</th>
                <th>Retained Deposits</th>
                <th>All Revenue</th>
            </tr></thead><tbody>";
        
        foreach ($months as $data) {
            $month = htmlspecialchars($data['month']);
            $month_display = date('F', strtotime($data['month'] . '-01')); // Convert 2025-05 to May
            
            $trades = (int) $data['trades'];
            $broker_earnings = number_format($data['broker_earnings'], 2);
            $delivery_earnings = number_format($data['delivery_earnings'], 2);
            $trade_total = number_format($data['trade_total'], 2);
            $rental_count = (int) $data['rental_count'];
            $rental_earnings = number_format($data['rental_earnings'], 2);
            $retained_deposits = number_format($data['retained_deposits'], 2);
            
            // Calculate total for this month
            $month_total = $data['trade_total'] + $data['rental_earnings'] + $data['retained_deposits'];
            $month_total_formatted = number_format($month_total, 2);
            
            // Update grand totals
            $grand_total_trades += $data['trades'];
            $grand_total_broker += $data['broker_earnings'];
            $grand_total_delivery += $data['delivery_earnings'];
            $grand_total_rentals += $data['rental_earnings'];
            $grand_total_deposits += $data['retained_deposits'];
            $grand_total_all += $month_total;
            
            $content .= "<tr>
                <td><strong>{$month_display}</strong></td>
                <td>{$trades}</td>
                <td>\${$broker_earnings}</td>
                <td>\${$delivery_earnings}</td>
                <td>\${$trade_total}</td>
                <td>{$rental_count}</td>
                <td>\${$rental_earnings}</td>
                <td>\${$retained_deposits}</td>
                <td><strong>\${$month_total_formatted}</strong></td>
            </tr>";
        }
        
        // Add totals row
        $content .= "<tr class='table-info'>
            <td><strong>TOTALS</strong></td>
            <td><strong>{$grand_total_trades}</strong></td>
            <td><strong>\$" . number_format($grand_total_broker, 2) . "</strong></td>
            <td><strong>\$" . number_format($grand_total_delivery, 2) . "</strong></td>
            <td><strong>\$" . number_format($grand_total_broker + $grand_total_delivery, 2) . "</strong></td>
            <td><strong>" . array_sum(array_column($months, 'rental_count')) . "</strong></td>
            <td><strong>\$" . number_format($grand_total_rentals, 2) . "</strong></td>
            <td><strong>\$" . number_format($grand_total_deposits, 2) . "</strong></td>
            <td><strong>\$" . number_format($grand_total_all, 2) . "</strong></td>
        </tr>";
        
        $content .= "</tbody></table></div>";
        
        // Prepare data for charts
        $chart_months = [];
        $chart_trade_data = [];
        $chart_rental_data = [];
        
        // Get only the last 6 months for charts in chronological order
        $last_months = array_slice($months_for_charts, -6, 6, true);
        
        foreach ($last_months as $month => $data) {
            $chart_months[] = date('M', strtotime($month . '-01')); // Convert to short month name (Jan, Feb, etc.)
            $chart_trade_data[] = $data['trade_total'];
            $chart_rental_data[] = $data['rental_earnings'] + $data['retained_deposits'];
        }
        
        // Calculate total revenue by source for pie chart
        $total_broker = $grand_total_broker;
        $total_delivery = $grand_total_delivery;
        $total_rental = $grand_total_rentals;
        $total_deposits = $grand_total_deposits;
        
        // Add charts
        $content .= "
        <div class='row mt-5'>
            <div class='col-md-6'>
                <h3>Revenue Breakdown</h3>
                <canvas id='revenueChart' width='400' height='300'></canvas>
            </div>
            <div class='col-md-6'>
                <h3>Monthly Trends</h3>
                <canvas id='monthlyChart' width='400' height='300'></canvas>
            </div>
        </div>
        
        <script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Revenue breakdown pie chart
            const ctxPie = document.getElementById('revenueChart').getContext('2d');
            new Chart(ctxPie, {
                type: 'pie',
                data: {
                    labels: ['Broker Fees', 'Delivery Fees', 'Rental Fees', 'Retained Deposits'],
                    datasets: [{
                        data: [
                            " . $total_broker . ",
                            " . $total_delivery . ",
                            " . $total_rental . ",
                            " . $total_deposits . "
                        ],
                        backgroundColor: [
                            'rgba(54, 162, 235, 0.7)',
                            'rgba(255, 206, 86, 0.7)',
                            'rgba(75, 192, 192, 0.7)',
                            'rgba(153, 102, 255, 0.7)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'right',
                        },
                        title: {
                            display: true,
                            text: 'Revenue Sources'
                        }
                    }
                }
            });
            
            // Monthly trends chart
            const ctxBar = document.getElementById('monthlyChart').getContext('2d');
            
            // Check if we have monthly data
            const monthLabels = " . json_encode($chart_months) . ";
            const tradeData = " . json_encode($chart_trade_data) . ";
            const rentalData = " . json_encode($chart_rental_data) . ";
            
            if (monthLabels.length > 0) {
                new Chart(ctxBar, {
                    type: 'bar',
                    data: {
                        labels: monthLabels,
                        datasets: [{
                            label: 'Trade Revenue',
                            data: tradeData,
                            backgroundColor: 'rgba(54, 162, 235, 0.5)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 1
                        }, {
                            label: 'Rental Revenue',
                            data: rentalData,
                            backgroundColor: 'rgba(75, 192, 192, 0.5)',
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Revenue ($)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Month'
                                }
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: 'Monthly Revenue Trends'
                            }
                        }
                    }
                });
            } else {
                // Display a message if there's no data
                ctxBar.canvas.height = 100;
                ctxBar.font = '16px Arial';
                ctxBar.textAlign = 'center';
                ctxBar.fillText('No monthly data available for selected period', 
                               ctxBar.canvas.width/2, 50);
            }
        });
        </script>";
        
    } else {
        $content .= "<p class='text-muted'>No earnings data available for {$selected_year}.</p>";
    }
    
    $conn->close();
}
   
// ====================[ DOWNLOAD EARNINGS CSV ]====================
if (isset($action) && $action === 'download_earnings_csv') {
    $selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=earnings_report_' . $selected_year . '_' . date('Y-m-d') . '.csv');

    $conn = connectDB();

    // Fetch annual summary
    $annual_sql = [
        'trades' => "
            SELECT 
                SUM(trade_value * (broker_fee / 100)) AS broker_earnings,
                SUM(delivery_fee) AS delivery_earnings,
                COUNT(*) AS count
            FROM trades WHERE YEAR(created_at) = {$selected_year}",
        'rentals' => "
            SELECT 
                SUM(total_amount) AS rental_earnings,
                SUM(deposit - IFNULL(deposit_returned, 0)) AS retained_deposits,
                COUNT(*) AS count
            FROM rentals WHERE YEAR(rental_start) = {$selected_year}"
    ];

    $annual = [
        'broker_earnings' => 0, 'delivery_earnings' => 0, 'trades' => 0,
        'rental_earnings' => 0, 'retained_deposits' => 0, 'rentals' => 0
    ];

    foreach ($annual_sql as $type => $sql) {
        $result = $conn->query($sql);
        if ($row = $result->fetch_assoc()) {
            if ($type === 'trades') {
                $annual['broker_earnings'] = floatval($row['broker_earnings']);
                $annual['delivery_earnings'] = floatval($row['delivery_earnings']);
                $annual['trades'] = intval($row['count']);
            } else {
                $annual['rental_earnings'] = floatval($row['rental_earnings']);
                $annual['retained_deposits'] = floatval($row['retained_deposits']);
                $annual['rentals'] = intval($row['count']);
            }
        }
    }

    $months = [];

    // Fetch monthly breakdown
    $monthly_queries = [
        'trades' => "
            SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                   SUM(trade_value * (broker_fee / 100)) AS broker,
                   SUM(delivery_fee) AS delivery,
                   COUNT(*) AS count
            FROM trades WHERE YEAR(created_at) = {$selected_year} GROUP BY month",
        'rentals' => "
            SELECT DATE_FORMAT(rental_start, '%Y-%m') AS month,
                   SUM(total_amount) AS rental,
                   SUM(deposit - IFNULL(deposit_returned, 0)) AS deposit,
                   COUNT(*) AS count
            FROM rentals WHERE YEAR(rental_start) = {$selected_year} GROUP BY month"
    ];

    foreach ($monthly_queries as $type => $sql) {
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            $m = $row['month'];
            if (!isset($months[$m])) {
                $months[$m] = [
                    'trades' => 0, 'broker' => 0, 'delivery' => 0,
                    'rentals' => 0, 'rental' => 0, 'deposit' => 0
                ];
            }
            if ($type === 'trades') {
                $months[$m]['trades'] = (int)$row['count'];
                $months[$m]['broker'] = floatval($row['broker']);
                $months[$m]['delivery'] = floatval($row['delivery']);
            } else {
                $months[$m]['rentals'] = (int)$row['count'];
                $months[$m]['rental'] = floatval($row['rental']);
                $months[$m]['deposit'] = floatval($row['deposit']);
            }
        }
    }

    krsort($months);

    $output = fopen('php://output', 'w');

    fputcsv($output, ["Back2Work Equipment - Earnings Report for {$selected_year}"]);
    fputcsv($output, ["Generated on", date('Y-m-d H:i:s')]);
    fputcsv($output, []);

    fputcsv($output, ['ANNUAL SUMMARY']);
    fputcsv($output, ['Trades', $annual['trades']]);
    fputcsv($output, ['Broker Fees', number_format($annual['broker_earnings'], 2)]);
    fputcsv($output, ['Delivery Fees', number_format($annual['delivery_earnings'], 2)]);
    fputcsv($output, ['Rentals', $annual['rentals']]);
    fputcsv($output, ['Rental Earnings', number_format($annual['rental_earnings'], 2)]);
    fputcsv($output, ['Retained Deposits', number_format($annual['retained_deposits'], 2)]);
    $total = $annual['broker_earnings'] + $annual['delivery_earnings'] + $annual['rental_earnings'] + $annual['retained_deposits'];
    fputcsv($output, ['TOTAL REVENUE', number_format($total, 2)]);
    fputcsv($output, []);

    fputcsv($output, [
        'Month', '# Trades', 'Broker Fees', 'Delivery Fees',
        '# Rentals', 'Rental Fees', 'Retained Deposits', 'Month Total'
    ]);

    foreach ($months as $m => $d) {
        $total_month = $d['broker'] + $d['delivery'] + $d['rental'] + $d['deposit'];
        fputcsv($output, [
            date('F Y', strtotime($m . '-01')),
            $d['trades'], number_format($d['broker'], 2), number_format($d['delivery'], 2),
            $d['rentals'], number_format($d['rental'], 2), number_format($d['deposit'], 2),
            number_format($total_month, 2)
        ]);
    }

    fclose($output);
    $conn->close();
    exit;
}


// =======================[ ACTION: delete_item ]=======================
if ($action == 'delete_item' && isset($_GET['item_id'])) {
    $item_id = intval($_GET['item_id']);
    $conn = connectDB();
    $stmt = $conn->prepare("DELETE FROM items WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    if ($stmt->execute()) {
        header("Location: ?page=inventory&msg=deleted");
        exit;
    } else {
        $content .= "<div class='alert alert-danger'>Error deleting item: " . $conn->error . "</div>";
    }
    $stmt->close();
    $conn->close();
}

// ====================[ ACTION: DELETE TRADE ]====================
if ($action === 'delete_trade' && isset($_GET['trade_id'])) {
    $trade_id = (int) $_GET['trade_id'];
    $conn = connectDB();
    $stmt = $conn->prepare("DELETE FROM trades WHERE id = ?");
    $stmt->bind_param("i", $trade_id);
    if ($stmt->execute()) {
        header("Location: ?page=trade_log&msg=deleted");
        exit;
    } else {
        $content .= "<div class='alert alert-danger'>Error deleting trade: " . $conn->error . "</div>";
    }
    $stmt->close();
    $conn->close();
}

// ====================[ EXPORT CSV: TRADE LOG ]====================
if (isset($action) && $action === 'export_trade_log_csv') {
    $conn = connectDB();
    
    // First ensure the trades table has a status column if it doesn't already
    $result = $conn->query("SHOW COLUMNS FROM trades LIKE 'status'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE trades ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
    }
    
    $filename = "trade_log_" . date("Ymd_His") . ".csv";
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename={$filename}");
    $output = fopen("php://output", "w");
    fputcsv($output, ["Contract ID", "Item", "Make/Model", "Owner", "Traded For", "Value", "Broker Fee", "Date", "Status"]);
    $sql = "
        SELECT t.*, i.description, i.make_model, c.name AS consignor_name
        FROM trades t
        LEFT JOIN items i ON t.item_id = i.id
        LEFT JOIN consignors c ON i.consignor_id = c.id
        ORDER BY t.created_at DESC
    ";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                "T-" . str_pad($row['id'], 5, '0', STR_PAD_LEFT),
                $row['description'],
                $row['make_model'],
                $row['consignor_name'],
                $row['trade_for'],
                $row['trade_value'],
                $row['broker_fee'],
                $row['created_at'],
                $row['status'] ?? 'active' // Default to 'active' if status is null
            ]);
        }
    }
    fclose($output);
    $conn->close();
    exit;
}
if (isset($action) && $action === 'export_trade_log_csv') {
    $conn = connectDB();
    $filename = "trade_log_" . date("Ymd_His") . ".csv";
    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename={$filename}");
    $output = fopen("php://output", "w");
    fputcsv($output, ["Contract ID", "Item", "Make/Model", "Owner", "Traded For", "Value", "Broker Fee", "Date", "Status"]);
    $sql = "
        SELECT t.*, i.description, i.make_model, c.name AS consignor_name
        FROM trades t
        LEFT JOIN items i ON t.item_id = i.id
        LEFT JOIN consignors c ON i.consignor_id = c.id
        ORDER BY t.created_at DESC
    ";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                "T-" . str_pad($row['id'], 5, '0', STR_PAD_LEFT),
                $row['description'],
                $row['make_model'],
                $row['consignor_name'],
                $row['trade_for'],
                $row['trade_value'],
                $row['broker_fee'],
                $row['created_at'],
                $row['status']
            ]);
        }
    }
    fclose($output);
    $conn->close();
    exit;
}

// ====================[ PAGE: TRADE LOG ]====================
if (isset($page) && $page === 'trade_log') {
    $conn = connectDB();
    $sql = "
        SELECT t.*, i.description, i.make_model, c.name AS consignor_name
        FROM trades t
        LEFT JOIN items i ON t.item_id = i.id
        LEFT JOIN consignors c ON i.consignor_id = c.id
        ORDER BY t.created_at DESC
    ";
    $result = $conn->query($sql);
    $content .= "<h2 class='mt-4'>Trade History Log</h2>";
    if ($result && $result->num_rows > 0) {
        $content .= "<div class='table-responsive'><table class='table table-bordered'>
        <thead><tr>
            <th>Contract #</th>
            <th>Item</th>
            <th>Owner</th>
            <th>Traded For</th>
            <th>Value</th>
            <th>Broker Fee</th>
            <th>Status</th>
            <th>Date</th>
            <th>Actions</th>
        </tr></thead><tbody>";
        while ($row = $result->fetch_assoc()) {
            $contract_id = "T-" . str_pad($row['id'], 5, '0', STR_PAD_LEFT);
            $value = number_format($row['trade_value'], 2);
            $fee = number_format($row['broker_fee'], 2);
            $date = date('m/d/Y', strtotime($row['created_at']));
            $status = ucfirst($row['status'] ?? 'Active');
            $content .= "<tr>
                <td>{$contract_id}</td>
                <td>" . htmlspecialchars($row['description']) . "<br><small>" . htmlspecialchars($row['make_model']) . "</small></td>
                <td>" . htmlspecialchars($row['consignor_name']) . "</td>
                <td>" . htmlspecialchars($row['trade_for']) . "</td>
                <td>\${$value}</td>
                <td>{$fee}%</td>
                <td>{$status}</td>
                <td>{$date}</td>
                <td>
                    <a href='?action=trade_contract&trade_id={$row['id']}' target='_blank' class='btn btn-warning'>View Contract</a>
                    <a href='?action=start_trade&item_id={$row['item_id']}&edit=1&trade_id={$row['id']}' class='btn btn-sm btn-warning'>Edit</a>
                    <a href='?action=delete_trade&trade_id={$row['id']}' class='btn btn-sm btn-danger' onclick='return confirm(\"Delete this trade?\")'>Delete</a>
                </td>
            </tr>";
        }
        $content .= "</tbody></table></div>";
    } else {
        $content .= "<div class='alert alert-info'>No trade records found.</div>";
    }
    $conn->close();
}

// ====================[ ACTION: REDEEM CREDIT ]====================
if ($action === 'redeem_credit' && isset($_GET['customer'])) {
    $customer = urldecode($_GET['customer']);
    $conn = connectDB();
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS credits_redeemed (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        redeemed_by VARCHAR(100) NOT NULL,
        date_redeemed DATE NOT NULL
    )");
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $amount = floatval($_POST['amount']);
        $redeemed_by = $_POST['redeemed_by'];
        $date_redeemed = date('Y-m-d');
        // ? Dynamically calculate available credit
        $available_credit = 0;
        $stmt = $conn->prepare("
            SELECT 
                IFNULL(SUM(c.amount), 0) - 
                IFNULL((
                    SELECT SUM(r.amount)
                    FROM credits_redeemed r
                    WHERE r.customer_name = c.customer_name
                ), 0) AS available
            FROM customer_credits c
            WHERE c.customer_name = ?
        ");
        $stmt->bind_param("s", $customer);
        $stmt->execute();
        $stmt->bind_result($available_credit);
        $stmt->fetch();
        $stmt->close();
        if ($amount > $available_credit) {
            $content .= "<div class='alert alert-danger'>Cannot redeem more than available credit ($" . number_format($available_credit, 2) . ").</div>";
        } else {
            $stmt = $conn->prepare("INSERT INTO credits_redeemed (customer_name, amount, redeemed_by, date_redeemed) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sdss", $customer, $amount, $redeemed_by, $date_redeemed);
            if ($stmt->execute()) {
                $content .= "<div class='alert alert-success'>Successfully redeemed \${$amount} for " . htmlspecialchars($customer) . ".</div>";
                $content .= "<meta http-equiv='refresh' content='2;url=?page=credits_summary'>";
            } else {
                $content .= "<div class='alert alert-danger'>Error saving redemption: " . $conn->error . "</div>";
            }
            $stmt->close();
        }
    } else {
        // Display redemption form
        $content .= "<h2 class='mt-4'>Redeem Credit for " . htmlspecialchars($customer) . "</h2>
        <form method='post'>
            <div class='form-group'>
                <label>Amount to Redeem</label>
                <input type='number' name='amount' step='0.01' class='form-control' required>
            </div>
            <div class='form-group'>
                <label>Redeemed By</label>
                <input type='text' name='redeemed_by' class='form-control' required>
            </div>
            <button type='submit' class='btn btn-primary'>Submit</button>
            <a href='?page=credits_summary' class='btn btn-secondary'>Cancel</a>
        </form>";
    }
    $conn->close();
}

      // ====================[ DELETE CONSIGNOR ]====================
if ($action === 'delete_consignor' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $consignor_id = (int) $_GET['id'];
    $conn = connectDB();
    
    // Check if consignor has items first
    $check = $conn->prepare("SELECT COUNT(*) as count FROM items WHERE consignor_id = ?");
    $check->bind_param("i", $consignor_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    
    if ($row['count'] > 0) {
        $message = "<div class='alert alert-warning'>This consignor has items and cannot be deleted.</div>";
    } else {
        $stmt = $conn->prepare("DELETE FROM consignors WHERE id = ?");
        $stmt->bind_param("i", $consignor_id);
        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>Consignor deleted successfully.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Failed to delete consignor: " . $conn->error . "</div>";
        }
    }
    $content .= $message;
    $conn->close();
}
    
    // Display page
    return $content;
}  // End of handleRequest function
// Basic page template
function displayPage($content) {
    $current = basename($_SERVER["PHP_SELF"]);
    
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BACK2WORK EQUIPMENT Consignment System</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
    
.hide-pay-btn {
    display: none !important;
}
.form-group {
    margin-bottom: 1rem;
}
.form-control {
    display: block;
    width: 100%;
    padding: 0.375rem 0.75rem;
    font-size: 1rem;
}
label {
    font-weight: bold;
    margin-bottom: 0.5rem;
    display: block;
}
        .dashboard-stats {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .stat-box {
            flex: 1;
            min-width: 200px;
            margin: 10px;
            padding: 15px;
            border-radius: 5px;
            background-color: #f8f9fa;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-box.warning {
            background-color: #fff3cd;
        }
        .stat-box.danger {
            background-color: #f8d7da;
        }
        .stat-box.success {
            background-color: #d4edda;
        }
        .stat-count {
            font-size: 3.5rem;
            font-weight: bold;
            margin: 10px 0;
        }
        .quick-actions {
            margin: 20px 0;
        }
        .quick-actions a {
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .tabs { margin: 20px 0; }
        .tabs a {
            display: inline-block;
            padding: 10px 16px;
            margin-right: 5px;
            background: #f0f0f0;
            color: #333;
            text-decoration: none;
            border-radius: 5px 5px 0 0;
            border: 1px solid #ccc;
            border-bottom: none;
        }
        .tabs a.active {
            background: #fff;
            font-weight: bold;
            border-bottom: 1px solid #fff;
        }
    </style>
</head>
<body>
    <hr>
    
    <div class="container">
            <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom mb-3">
    <a class="navbar-brand font-weight-bold text-white" href="?page=dashboard">Back2Work!</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link text-white" href="?page=dashboard">Dashboard</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=inventory">Inventory</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=consignors">Consignors</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=sales_history">Sales</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=rentals">Rentals</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=aging_inventory">Aging Items</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=promotions">Promotions</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=trade_log">Trade Report</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=tax_report">Tax Summary</a></li>
            <li class="nav-item"><a class="nav-link text-white" href="?page=broker_earnings">Earnings</a></li>
        </ul>
    </div>
</nav>
            
            <main role="main">
                ' . $content . '
            </main>
            
            <footer class="mt-5 pt-3 border-top text-center">
                <p>&copy; ' . date('Y') . ' BACK2WORK EQUIPMENT</p>
            </footer>
        </div>
        
        <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    </body>
    </html>';
    
    return $html;
}
// Main execution
$content = handleRequest();
echo displayPage($content);
return $content;

?>
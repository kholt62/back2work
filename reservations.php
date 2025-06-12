<?php
session_start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Handle reservation actions
// Handle reservation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'update_reservation') {
        // YOUR OLD CODE STARTS HERE - DELETE EVERYTHING FROM HERE...
        $reservation_id = $_POST['reservation_id'];
        $status = $_POST['status'];
        $admin_notes = $_POST['admin_notes'] ?? '';
        $site_assignment = $_POST['site_assignment'] ?? null;
        
        try {
            $db->beginTransaction();
            
            // Update reservation status
            $stmt = $db->prepare("
                UPDATE reservation_requests 
                SET status = ?, admin_notes = ?, reviewed_at = NOW(), reviewed_by = 'Admin'
                WHERE id = ?
            ");
            $stmt->execute([$status, $admin_notes, $reservation_id]);
            
            // If approved and site assigned, block the dates
            if ($status === 'approved' && $site_assignment) {
                // ... more old code here
            }
            
            $db->commit();
            $success_message = "Reservation " . $status . " successfully!";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error updating reservation: " . $e->getMessage();
        }
        // ...TO HERE - DELETE ALL THE OLD CODE
    }

// ===================================================================
// REPLACE ALL THE OLD CODE ABOVE WITH THE NEW CODE BELOW:
// ===================================================================

    if ($_POST['action'] === 'update_reservation') {
        $reservation_id = $_POST['reservation_id'];
        $status = $_POST['status'];
        $admin_notes = $_POST['admin_notes'] ?? '';
        $site_assignment = $_POST['site_assignment'] ?? null;
        
        try {
            $db->beginTransaction();
            
            // Get the reservation details first
            $reservation_query = $db->prepare("
                SELECT rr.*, t.full_name, t.phone, t.email 
                FROM reservation_requests rr 
                JOIN tenants t ON rr.tenant_id = t.id 
                WHERE rr.id = ?
            ");
            $reservation_query->execute([$reservation_id]);
            $reservation = $reservation_query->fetch();
            
            if (!$reservation) {
                throw new Exception("Reservation not found.");
            }
            
            // Update reservation status - ALWAYS update these fields
            $update_query = $db->prepare("
                UPDATE reservation_requests 
                SET status = ?, 
                    admin_notes = ?, 
                    reviewed_at = NOW(), 
                    reviewed_by = ?,
                    assigned_site = ?
                WHERE id = ?
            ");
            
            // IMPORTANT: Always pass the site assignment, even if null
            $update_result = $update_query->execute([
                $status, 
                $admin_notes, 
                $_SESSION['user_name'] ?? 'Admin',
                $site_assignment, // This MUST be passed even if null
                $reservation_id
            ]);
            
            if (!$update_result) {
                throw new Exception("Failed to update reservation status");
            }
            
            // Debug: Log what we just updated
            error_log("Updated reservation {$reservation_id}: status={$status}, assigned_site={$site_assignment}");
            
            // If approved, handle site assignment and tenant status
            if ($status === 'approved') {
                // Update tenant status to show they have an approved reservation
                $tenant_update = $db->prepare("
                    UPDATE tenants 
                    SET status = 'reservation_confirmed',
                        stay_type = ?,
                        notes = CONCAT(COALESCE(notes, ''), '\nReservation #', ?, ' approved on ', NOW())
                    WHERE id = ?
                ");
                $tenant_update->execute([
                    $reservation['stay_type'], 
                    $reservation_id, 
                    $reservation['tenant_id']
                ]);
                
                // If site is assigned, reserve it
                if ($site_assignment) {
                    // Check if the site is actually available
                    $site_check = $db->prepare("
                        SELECT id, site_number, status 
                        FROM sites 
                        WHERE site_number = ? AND status IN ('available', 'maintenance')
                    ");
                    $site_check->execute([$site_assignment]);
                    $site = $site_check->fetch();
                    
                    if ($site) {
                        // Reserve the site for this reservation
                        $site_reserve = $db->prepare("
                            UPDATE sites 
                            SET status = 'reserved', 
                                notes = CONCAT(COALESCE(notes, ''), '\nReserved for: ', ?, ' (Res #', ?, ') from ', ?, ' to ', ?),
                                last_updated = NOW()
                            WHERE id = ?
                        ");
                        $site_reserve_result = $site_reserve->execute([
                            $reservation['full_name'], 
                            $reservation_id,
                            $reservation['check_in_date'],
                            $reservation['check_out_date'],
                            $site['id']
                        ]);
                        
                        if ($site_reserve_result) {
                            $success_message = "Reservation approved! Site {$site_assignment} has been reserved for {$reservation['full_name']} from {$reservation['check_in_date']} to {$reservation['check_out_date']}.";
                            error_log("Successfully reserved site {$site_assignment} for reservation {$reservation_id}");
                        } else {
                            throw new Exception("Failed to reserve site {$site_assignment}");
                        }
                    } else {
                        throw new Exception("Site {$site_assignment} is not available. Please select a different site.");
                    }
                } else {
                    $success_message = "Reservation approved successfully! Site will be assigned at check-in.";
                }
                
            } elseif ($status === 'denied') {
                $success_message = "Reservation has been denied.";
            }
            
            $db->commit();
            
            // Log success
            error_log("Reservation {$reservation_id} successfully {$status} by " . ($_SESSION['user_name'] ?? 'Admin'));
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Error updating reservation: " . $e->getMessage();
            error_log("Reservation approval error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
    }

    // KEEP YOUR OTHER EXISTING CODE BELOW (admin_cancel_reservation, update_refund_status, etc.)
    // Handle admin cancellation
    elseif ($_POST['action'] === 'admin_cancel_reservation') {
        // ... keep your existing cancellation code ...
    }
    
    // Handle refund status updates
    elseif ($_POST['action'] === 'update_refund_status') {
        // ... keep your existing refund code ...
    }
}

// Get all reservation requests with tenant info (including cancelled if columns exist)
try {
    // First check if cancelled columns exist
    $has_cancellation_columns = false;
    try {
        $db->query("SELECT cancelled_at FROM reservation_requests LIMIT 1");
        $has_cancellation_columns = true;
    } catch (Exception $e) {
        // Columns don't exist yet
    }
    
    if ($has_cancellation_columns) {
        $reservations = $db->query("
            SELECT 
                rr.*,
                t.full_name,
                t.phone,
                t.email,
                t.rv_type,
                t.total_guests as tenant_guests,
                DATEDIFF(rr.check_out_date, rr.check_in_date) as duration_days
            FROM reservation_requests rr
            JOIN tenants t ON rr.tenant_id = t.id
            ORDER BY 
                CASE rr.status 
                    WHEN 'pending' THEN 1 
                    WHEN 'approved' THEN 2 
                    WHEN 'cancelled' THEN 3
                    WHEN 'denied' THEN 4 
                END,
                rr.requested_at DESC
        ")->fetchAll();
    } else {
        // Fallback query without cancellation columns
        $reservations = $db->query("
            SELECT 
                rr.*,
                t.full_name,
                t.phone,
                t.email,
                t.rv_type,
                t.total_guests as tenant_guests,
                DATEDIFF(rr.check_out_date, rr.check_in_date) as duration_days
            FROM reservation_requests rr
            JOIN tenants t ON rr.tenant_id = t.id
            ORDER BY 
                CASE rr.status 
                    WHEN 'pending' THEN 1 
                    WHEN 'approved' THEN 2 
                    WHEN 'denied' THEN 3 
                END,
                rr.requested_at DESC
        ")->fetchAll();
    }
} catch (Exception $e) {
    $reservations = [];
    $error_message = "Error loading reservations: " . $e->getMessage();
}

// Get available sites
try {
    $available_sites = $db->query("SELECT id, site_number FROM sites WHERE status = 'available' ORDER BY site_number")->fetchAll();
} catch (Exception $e) {
    $available_sites = [];
}

// Group reservations by status
$reservations_by_status = [
    'pending' => [],
    'approved' => [],
    'denied' => [],
    'cancelled' => []
];

foreach($reservations as $reservation) {
    $status = $reservation['status'] ?? 'pending';
    if (!isset($reservations_by_status[$status])) {
        $reservations_by_status[$status] = [];
    }
    $reservations_by_status[$status][] = $reservation;
}

// Get recent activity stats
$stats = [
    'total_pending' => count($reservations_by_status['pending']),
    'this_month_approved' => count(array_filter($reservations_by_status['approved'], function($r) { 
        return isset($r['reviewed_at']) && date('Y-m', strtotime($r['reviewed_at'])) === date('Y-m'); 
    })),
    'this_week_requests' => count(array_filter($reservations, function($r) { 
        return strtotime($r['requested_at']) >= strtotime('-7 days'); 
    })),
    'total_cancelled' => count($reservations_by_status['cancelled']),
    'pending_refunds' => count(array_filter($reservations_by_status['cancelled'], function($r) {
        return isset($r['refund_status']) && $r['refund_status'] === 'pending';
    }))
];

// Helper function for status badges
function getStatusBadge($status) {
    switch($status) {
        case 'pending': return '<span class="badge bg-warning">Pending</span>';
        case 'approved': return '<span class="badge bg-success">Approved</span>';
        case 'denied': return '<span class="badge bg-danger">Denied</span>';
        case 'cancelled': return '<span class="badge bg-secondary">Cancelled</span>';
        default: return '<span class="badge bg-light text-dark">' . ucfirst($status) . '</span>';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reservation Management - Oakwood 79 RV Park</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8fdf4;
        }
        .dashboard-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: none;
            transition: transform 0.3s ease;
        }
        .dashboard-card:hover {
            transform: translateY(-3px);
        }
        .reservation-card {
            border-radius: 10px;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }
        .reservation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .stat-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-left: 4px solid;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .pending-card { border-left-color: #ffc107; }
        .approved-card { border-left-color: #28a745; }
        .activity-card { border-left-color: #17a2b8; }
        .cancelled-card { border-left-color: #6c757d; }
        .refund-card { border-left-color: #fd7e14; }
        .modal-header {
            background: linear-gradient(135deg, #2d5016, #3a6b1f);
            color: white;
        }
        .reservation-priority-high {
            border-left: 4px solid #dc3545 !important;
        }
        .reservation-priority-medium {
            border-left: 4px solid #ffc107 !important;
        }
        .reservation-priority-low {
            border-left: 4px solid #28a745 !important;
        }
        .cancelled-reservation {
            opacity: 0.8;
            border-left: 4px solid #6c757d !important;
        }
        .refund-pending {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 5px;
            padding: 5px 10px;
            margin-top: 5px;
        }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<div class="container-fluid px-4 py-4">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <h2 style="color: #2d5016;">
                <i class="fas fa-calendar-check me-2"></i>
                Reservation Management
            </h2>
            <div>
                <a href="new_reservation.php" class="btn btn-success me-2">
                    <i class="fas fa-plus me-1"></i>New Reservation
                </a>
                <a href="reservation_calendar.php" class="btn btn-primary me-2">
                    <i class="fas fa-calendar me-1"></i>Calendar View
                </a>
            </div>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Enhanced Stats Overview -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="stat-card pending-card">
                <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                <h3 class="text-warning"><?php echo $stats['total_pending']; ?></h3>
                <small>Pending Requests</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card approved-card">
                <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                <h3 class="text-success"><?php echo $stats['this_month_approved']; ?></h3>
                <small>Approved This Month</small>
            </div>
        </div>
        <div class="col-md-2">
            <div class="stat-card activity-card">
                <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                <h3 class="text-info"><?php echo $stats['this_week_requests']; ?></h3>
                <small>Requests This Week</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card cancelled-card">
                <i class="fas fa-times-circle fa-2x text-secondary mb-2"></i>
                <h3 class="text-secondary"><?php echo $stats['total_cancelled']; ?></h3>
                <small>Total Cancelled</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card refund-card">
                <i class="fas fa-credit-card fa-2x text-warning mb-2"></i>
                <h3 class="text-warning"><?php echo $stats['pending_refunds']; ?></h3>
                <small>Pending Refunds</small>
            </div>
        </div>
    </div>

    <!-- Pending Reservations Section -->
    <?php if (!empty($reservations_by_status['pending'])): ?>
    <div class="dashboard-card p-4 mb-4">
        <h5 class="text-warning mb-3">
            <i class="fas fa-exclamation-circle me-2"></i>
            Pending Reservations (<?php echo count($reservations_by_status['pending']); ?>)
        </h5>
        
        <div class="row">
            <?php foreach($reservations_by_status['pending'] as $reservation): ?>
                <?php
                // Determine priority based on check-in date
                $days_until_checkin = ceil((strtotime($reservation['check_in_date']) - time()) / (60 * 60 * 24));
                $priority_class = '';
                $priority_text = '';
                if ($days_until_checkin <= 3) {
                    $priority_class = 'reservation-priority-high';
                    $priority_text = 'HIGH PRIORITY';
                } elseif ($days_until_checkin <= 7) {
                    $priority_class = 'reservation-priority-medium';
                    $priority_text = 'MEDIUM PRIORITY';
                } else {
                    $priority_class = 'reservation-priority-low';
                    $priority_text = 'NORMAL';
                }
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card border-warning reservation-card <?php echo $priority_class; ?>">
                        <div class="card-header bg-warning text-dark d-flex justify-content-between">
                            <strong><?php echo htmlspecialchars($reservation['full_name']); ?></strong>
                            <small class="badge bg-dark"><?php echo $priority_text; ?></small>
                        </div>
                        <div class="card-body">
                            <div class="row g-1 small mb-3">
                                <div class="col-6"><strong>Check-In:</strong></div>
                                <div class="col-6"><?php echo date('M j, Y', strtotime($reservation['check_in_date'])); ?></div>
                                <div class="col-6"><strong>Check-Out:</strong></div>
                                <div class="col-6"><?php echo date('M j, Y', strtotime($reservation['check_out_date'])); ?></div>
                                <div class="col-6"><strong>Duration:</strong></div>
                                <div class="col-6"><?php echo $reservation['duration_days']; ?> days</div>
                                <div class="col-6"><strong>Stay Type:</strong></div>
                                <div class="col-6"><span class="badge bg-primary"><?php echo ucfirst($reservation['stay_type']); ?></span></div>
                                <div class="col-6"><strong>Guests:</strong></div>
                                <div class="col-6"><?php echo $reservation['guests']; ?> guest<?php echo $reservation['guests'] > 1 ? 's' : ''; ?></div>
                                <div class="col-6"><strong>Total Cost:</strong></div>
                                <div class="col-6"><strong class="text-success">$<?php echo number_format($reservation['total_cost'], 2); ?></strong></div>
                                <div class="col-6"><strong>Requested:</strong></div>
                                <div class="col-6"><?php echo date('M j, g:i A', strtotime($reservation['requested_at'])); ?></div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button class="btn btn-success btn-sm" onclick="approveReservation(<?php echo $reservation['id']; ?>)">
                                    <i class="fas fa-check me-1"></i>Approve & Assign Site
                                </button>
                                <div class="btn-group">
                                    <button class="btn btn-danger btn-sm" onclick="denyReservation(<?php echo $reservation['id']; ?>)">
                                        <i class="fas fa-times me-1"></i>Deny
                                    </button>
                                    <?php if($reservation['phone']): ?>
                                    <button class="btn btn-info btn-sm" onclick="contactGuest('<?php echo $reservation['phone']; ?>')">
                                        <i class="fas fa-phone me-1"></i>Call
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="dashboard-card p-4 mb-4">
        <div class="text-center text-muted py-4">
            <i class="fas fa-calendar-check" style="font-size: 48px; opacity: 0.3;"></i>
            <h5 class="mt-3">No Pending Reservations</h5>
            <p>All reservation requests have been processed.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- All Reservations Table -->
    <div class="dashboard-card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5><i class="fas fa-list me-2"></i>All Reservations</h5>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary active" onclick="filterReservations('all')">All</button>
                <button class="btn btn-outline-warning" onclick="filterReservations('pending')">Pending</button>
                <button class="btn btn-outline-success" onclick="filterReservations('approved')">Approved</button>
                <button class="btn btn-outline-danger" onclick="filterReservations('denied')">Denied</button>
                <button class="btn btn-outline-secondary" onclick="filterReservations('cancelled')">Cancelled</button>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover" id="reservations-table">
                <thead class="table-light">
                    <tr>
                        <th>Guest</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Stay Type</th>
                        <th>Guests</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($reservations as $reservation): ?>
                    <tr data-status="<?php echo $reservation['status']; ?>" class="<?php echo $reservation['status'] === 'cancelled' ? 'cancelled-reservation' : ''; ?>">
                        <td>
                            <strong><?php echo htmlspecialchars($reservation['full_name']); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($reservation['phone']); ?></small>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($reservation['check_in_date'])); ?></td>
                        <td><?php echo date('M j, Y', strtotime($reservation['check_out_date'])); ?></td>
                        <td><span class="badge bg-primary"><?php echo ucfirst($reservation['stay_type']); ?></span></td>
                        <td><?php echo $reservation['guests']; ?></td>
                        <td>
                            <strong class="text-success">$<?php echo number_format($reservation['total_cost'], 2); ?></strong>
                            <?php if ($reservation['status'] === 'cancelled' && isset($reservation['refund_amount']) && $reservation['refund_amount'] > 0): ?>
                                <div class="refund-pending">
                                    <small>Refund: $<?php echo number_format($reservation['refund_amount'], 2); ?> 
                                    (<?php echo ucfirst($reservation['refund_status'] ?? 'pending'); ?>)</small>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo getStatusBadge($reservation['status']); ?></td>
                        <td>
                            <?php if ($reservation['status'] === 'cancelled' && isset($reservation['cancelled_at'])): ?>
                                <?php echo date('M j, g:i A', strtotime($reservation['cancelled_at'])); ?>
                            <?php else: ?>
                                <?php echo date('M j, g:i A', strtotime($reservation['requested_at'])); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="viewReservationDetails(<?php echo $reservation['id']; ?>)" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if($reservation['status'] === 'pending'): ?>
                                <button class="btn btn-outline-success" onclick="approveReservation(<?php echo $reservation['id']; ?>)" title="Approve">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-outline-danger" onclick="denyReservation(<?php echo $reservation['id']; ?>)" title="Deny">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modals -->
<!-- Approve Reservation Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-check me-2"></i>Approve Reservation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_reservation">
                <input type="hidden" name="status" value="approved">
                <input type="hidden" name="reservation_id" id="approve_reservation_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Assign Site *</label>
                        <select class="form-select" name="site_assignment" required>
                            <option value="">-- Select Available Site --</option>
                            <?php foreach($available_sites as $site): ?>
                                <option value="<?php echo $site['site_number']; ?>">Site <?php echo $site['site_number']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Admin Notes</label>
                        <textarea class="form-control" name="admin_notes" rows="3" 
                                  placeholder="Approval notes, special instructions, etc."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Approving will:</strong>
                        <ul class="mt-2 mb-0">
                            <li>Reserve the selected site for the requested dates</li>
                            <li>Allow the guest to check in on their arrival date</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Approve Reservation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Deny Reservation Modal -->
<div class="modal fade" id="denyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-times me-2"></i>Deny Reservation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_reservation">
                <input type="hidden" name="status" value="denied">
                <input type="hidden" name="reservation_id" id="deny_reservation_id">
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Please provide a reason for denying this reservation.</strong>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Denial *</label>
                        <textarea class="form-control" name="admin_notes" rows="4" required
                                  placeholder="Please explain why this reservation was denied..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times me-2"></i>Deny Reservation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reservation Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-info-circle me-2"></i>Reservation Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="reservation-details-content">
                <!-- Content loaded by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function approveReservation(reservationId) {
    document.getElementById('approve_reservation_id').value = reservationId;
    const modal = new bootstrap.Modal(document.getElementById('approveModal'));
    modal.show();
}

function denyReservation(reservationId) {
    document.getElementById('deny_reservation_id').value = reservationId;
    const modal = new bootstrap.Modal(document.getElementById('denyModal'));
    modal.show();
}

function viewReservationDetails(reservationId) {
    const reservations = <?php echo json_encode($reservations); ?>;
    const reservation = reservations.find(r => r.id == reservationId);
    
    if (reservation) {
        let detailsHtml = `
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-primary">Guest Information</h6>
                    <p><strong>Name:</strong> ${reservation.full_name}</p>
                    <p><strong>Phone:</strong> ${reservation.phone || 'N/A'}</p>
                    <p><strong>Email:</strong> ${reservation.email || 'N/A'}</p>
                    <p><strong>RV Type:</strong> ${reservation.rv_type || 'Not specified'}</p>
                </div>
                <div class="col-md-6">
                    <h6 class="text-primary">Reservation Details</h6>
                    <p><strong>Check-In:</strong> ${new Date(reservation.check_in_date).toLocaleDateString()}</p>
                    <p><strong>Check-Out:</strong> ${new Date(reservation.check_out_date).toLocaleDateString()}</p>
                    <p><strong>Duration:</strong> ${reservation.duration_days} days</p>
                    <p><strong>Stay Type:</strong> ${reservation.stay_type.charAt(0).toUpperCase() + reservation.stay_type.slice(1)}</p>
                    <p><strong>Guests:</strong> ${reservation.guests}</p>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <h6 class="text-primary">Preferences</h6>
                    <p><strong>Site Preference:</strong> ${reservation.site_preference ? 'Site ' + reservation.site_preference : 'No preference'}</p>
                </div>
                <div class="col-md-6">
                    <h6 class="text-primary">Cost Breakdown</h6>
                    <p><strong>Estimated Cost:</strong> ${parseFloat(reservation.estimated_cost || 0).toFixed(2)}</p>
                    <p><strong>Sales Tax:</strong> ${parseFloat(reservation.sales_tax || 0).toFixed(2)}</p>
                    <p><strong>Total Cost:</strong> <strong class="text-success">${parseFloat(reservation.total_cost).toFixed(2)}</strong></p>
                </div>
            </div>
            
            ${reservation.special_requests ? `
                <div class="row">
                    <div class="col-12">
                        <h6 class="text-primary">Special Requests</h6>
                        <div class="alert alert-light">${reservation.special_requests}</div>
                    </div>
                </div>
            ` : ''}
            
            ${reservation.admin_notes ? `
                <div class="row">
                    <div class="col-12">
                        <h6 class="text-primary">Admin Notes</h6>
                        <div class="alert alert-info">${reservation.admin_notes}</div>
                    </div>
                </div>
            ` : ''}
        `;
        
        // Add cancellation details if cancelled
        if (reservation.status === 'cancelled') {
            detailsHtml += `
                <div class="row">
                    <div class="col-12">
                        <h6 class="text-danger">Cancellation Information</h6>
                        <div class="alert alert-warning">
                            ${reservation.cancelled_at ? `<p><strong>Cancelled:</strong> ${new Date(reservation.cancelled_at).toLocaleString()}</p>` : ''}
                            ${reservation.cancelled_by ? `<p><strong>Cancelled By:</strong> ${reservation.cancelled_by === 'admin' ? 'Administrator' : 'Guest'}</p>` : ''}
                            ${reservation.cancellation_reason ? `<p><strong>Reason:</strong> ${reservation.cancellation_reason}</p>` : ''}
                            ${reservation.refund_amount > 0 ? `
                                <p><strong>Refund:</strong> ${parseFloat(reservation.refund_amount).toFixed(2)} 
                                (Status: ${reservation.refund_status || 'pending'})</p>
                            ` : '<p><strong>Refund:</strong> None</p>'}
                        </div>
                    </div>
                </div>
            `;
        }
        
        detailsHtml += `
            <div class="row">
                <div class="col-12">
                    <h6 class="text-primary">Status Information</h6>
                    <p><strong>Status:</strong> <span class="badge bg-${
                        reservation.status === 'pending' ? 'warning' : 
                        reservation.status === 'approved' ? 'success' : 
                        reservation.status === 'cancelled' ? 'secondary' : 'danger'
                    }">${reservation.status.charAt(0).toUpperCase() + reservation.status.slice(1)}</span></p>
                    <p><strong>Requested:</strong> ${new Date(reservation.requested_at).toLocaleString()}</p>
                    ${reservation.reviewed_at ? `<p><strong>Reviewed:</strong> ${new Date(reservation.reviewed_at).toLocaleString()}</p>` : ''}
                </div>
            </div>
        `;
        
        document.getElementById('reservation-details-content').innerHTML = detailsHtml;
        const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
        modal.show();
    }
}

function contactGuest(phone) {
    if (phone && phone !== 'N/A') {
        if (confirm(`Call ${phone}?`)) {
            window.open(`tel:${phone}`);
        }
    } else {
        alert('No phone number available for this guest.');
    }
}

function filterReservations(status) {
    const table = document.getElementById('reservations-table');
    const rows = table.querySelectorAll('tbody tr');
    
    // Update button states
    document.querySelectorAll('.btn-group .btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    
    rows.forEach(row => {
        if (status === 'all' || row.getAttribute('data-status') === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Auto-refresh notification counts
setInterval(function() {
    fetch('get_notification_counts.php')
        .then(response => response.json())
        .then(data => {
            if (data.error) return;
            
            // Update pending count
            const pendingElement = document.querySelector('.pending-card h3');
            if (pendingElement && data.pending_reservations !== undefined) {
                pendingElement.textContent = data.pending_reservations;
            }
            
            // Update refund count
            const refundElement = document.querySelector('.refund-card h3');
            if (refundElement && data.pending_refunds !== undefined) {
                refundElement.textContent = data.pending_refunds;
            }
            
            // Update cancelled count
            const cancelledElement = document.querySelector('.cancelled-card h3');
            if (cancelledElement && data.total_cancelled !== undefined) {
                cancelledElement.textContent = data.total_cancelled;
            }
        })
        .catch(error => console.log('Auto-refresh error:', error));
}, 120000); // Every 2 minutes
</script>

</body>
</html>
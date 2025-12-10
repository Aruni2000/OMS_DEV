<?php
/**
 * City Details Modal Content
 * This file returns the detailed information for a specific city
 */

// Start session management
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo '<div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
            <i class="fas fa-lock" style="font-size: 2em; margin-bottom: 10px;"></i>
            <h4>Access Denied</h4>
            <p>Please log in to view city details.</p>
          </div>';
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/OMS/dist/connection/db_connection.php');

// Get city ID from request
$cityId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($cityId <= 0) {
    echo '<div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
            <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
            <h4>Invalid City ID</h4>
            <p>Please provide a valid city ID.</p>
          </div>';
    exit();
}

// Prepare and execute query to get city details
$sql = "SELECT c.*, 
               d.district_name, 
               d.province,
               z.zone_name,
               z.zone_type,
               z.zone_number,
               z.delivery_charge,
               z.delivery_days,
               z.created_at as zone_created_at,
               z.updated_at as zone_updated_at
        FROM city_table c 
        LEFT JOIN district_table d ON c.district_id = d.district_id
        LEFT JOIN zone_table z ON c.zone_id = z.zone_id
        WHERE c.city_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $cityId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
            <i class="fas fa-search" style="font-size: 2em; margin-bottom: 10px;"></i>
            <h4>City Not Found</h4>
            <p>No city found with ID: ' . htmlspecialchars($cityId) . '</p>
          </div>';
    exit();
}

$city = $result->fetch_assoc();
?>

<style>
.city-details-container {
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.city-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e9ecef;
}

.city-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.city-title h2 {
    margin: 0;
    color: #2c3e50;
    font-size: 1.5em;
}

.city-id-badge {
    background: #007bff;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85em;
    font-weight: 500;
}

.status-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.status-active .status-dot {
    background: #28a745;
}

.status-inactive .status-dot {
    background: #dc3545;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.detail-card {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    transition: transform 0.2s ease;
}

.detail-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
}

.card-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2em;
    color: white;
}

.card-icon.location { background: #17a2b8; }
.card-icon.zone { background: #28a745; }
.card-icon.delivery { background: #ffc107; color: #212529; }
.card-icon.info { background: #6f42c1; }

.card-title {
    font-size: 1.1em;
    font-weight: 600;
    color: #2c3e50;
    margin: 0;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.detail-item:last-child {
    border-bottom: none;
}

.detail-label {
    font-weight: 500;
    color: #6c757d;
    font-size: 0.9em;
}

.detail-value {
    font-weight: 500;
    color: #2c3e50;
    text-align: right;
}

.zone-type-badge {
    padding: 4px 10px;
    border-radius: 15px;
    font-size: 0.8em;
    font-weight: 500;
    text-transform: uppercase;
}

.zone-type-suburb {
    background: #d4edda;
    color: #155724;
}

.zone-type-outstation {
    background: #fff3cd;
    color: #856404;
}

.zone-type-remote {
    background: #f8d7da;
    color: #721c24;
}

.delivery-charge {
    font-size: 1.2em;
    font-weight: 600;
    color: #28a745;
}

.na-text {
    color: #6c757d;
    font-style: italic;
}

.timestamp {
    font-size: 0.85em;
    color: #6c757d;
}

.alert-info {
    background: #e7f3ff;
    border: 1px solid #b6d7ff;
    border-radius: 6px;
    padding: 15px;
    margin-top: 20px;
}

.alert-info .alert-icon {
    color: #0066cc;
    margin-right: 8px;
}

@media (max-width: 768px) {
    .details-grid {
        grid-template-columns: 1fr;
    }
    
    .city-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .detail-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
    
    .detail-value {
        text-align: left;
    }
}
</style>

<div class="city-details-container">
    <!-- City Header -->
    <div class="city-header">
        <div class="city-title">
            <h2><?php echo htmlspecialchars($city['city_name']); ?></h2>
            <span class="city-id-badge">ID: <?php echo htmlspecialchars($city['city_id']); ?></span>
        </div>
        <div class="status-indicator <?php echo $city['is_active'] ? 'status-active' : 'status-inactive'; ?>">
            <span class="status-dot"></span>
            <?php echo $city['is_active'] ? 'Active' : 'Inactive'; ?>
        </div>
    </div>

    <!-- Details Grid -->
    <div class="details-grid">
        
        <!-- Location Information -->
        <div class="detail-card">
            <div class="card-header">
                <div class="card-icon location">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <h3 class="card-title">Location Details</h3>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">City Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($city['city_name']); ?></span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">District</span>
                <span class="detail-value">
                    <?php echo !empty($city['district_name']) ? htmlspecialchars($city['district_name']) : '<span class="na-text">Not assigned</span>'; ?>
                </span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Province</span>
                <span class="detail-value">
                    <?php echo !empty($city['province']) ? htmlspecialchars($city['province']) : '<span class="na-text">Not available</span>'; ?>
                </span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Postal Code</span>
                <span class="detail-value">
                    <?php echo !empty($city['postal_code']) ? htmlspecialchars($city['postal_code']) : '<span class="na-text">Not available</span>'; ?>
                </span>
            </div>
        </div>

        <!-- Zone Information -->
        <div class="detail-card">
            <div class="card-header">
                <div class="card-icon zone">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h3 class="card-title">Zone Details</h3>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Zone Name</span>
                <span class="detail-value">
                    <?php echo !empty($city['zone_name']) ? htmlspecialchars($city['zone_name']) : '<span class="na-text">Not assigned</span>'; ?>
                </span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Zone Type</span>
                <span class="detail-value">
                    <?php 
                    if (!empty($city['zone_type'])) {
                        $zoneType = htmlspecialchars($city['zone_type']);
                        $badgeClass = "zone-type-" . strtolower($zoneType);
                        echo "<span class=\"zone-type-badge $badgeClass\">" . ucfirst($zoneType) . "</span>";
                    } else {
                        echo '<span class="na-text">Not available</span>';
                    }
                    ?>
                </span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Zone Number</span>
                <span class="detail-value">
                    <?php echo !empty($city['zone_number']) ? htmlspecialchars($city['zone_number']) : '<span class="na-text">Not assigned</span>'; ?>
                </span>
            </div>
        </div>

        <!-- Delivery Information -->
        <!-- <div class="detail-card">
            <div class="card-header">
                <div class="card-icon delivery">
                    <i class="fas fa-truck"></i>
                </div>
                <h3 class="card-title">Delivery Information</h3>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Delivery Charge</span>
                <span class="detail-value">
                    <?php 
                    if (isset($city['delivery_charge']) && $city['delivery_charge'] !== null) {
                        echo '<span class="delivery-charge">Rs. ' . number_format($city['delivery_charge'], 2) . '</span>';
                    } else {
                        echo '<span class="na-text">Not set</span>';
                    }
                    ?>
                </span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Delivery Days</span>
                <span class="detail-value">
                    <?php 
                    if (isset($city['delivery_days']) && $city['delivery_days'] !== null) {
                        $days = (int)$city['delivery_days'];
                        echo $days . ' ' . ($days == 1 ? 'day' : 'days');
                    } else {
                        echo '<span class="na-text">Not set</span>';
                    }
                    ?>
                </span>
            </div>
        </div> -->

        <!-- System Information -->
        <div class="detail-card">
            <div class="card-header">
                <div class="card-icon info">
                    <i class="fas fa-info-circle"></i>
                </div>
                <h3 class="card-title">System Information</h3>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">City Created</span>
                <span class="detail-value timestamp">
                    <?php echo date('M d, Y \a\t h:i A', strtotime($city['created_at'])); ?>
                </span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Last Updated</span>
                <span class="detail-value timestamp">
                    <?php echo date('M d, Y \a\t h:i A', strtotime($city['updated_at'])); ?>
                </span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">District ID</span>
                <span class="detail-value">
                    <?php echo !empty($city['district_id']) ? htmlspecialchars($city['district_id']) : '<span class="na-text">Not assigned</span>'; ?>
                </span>
            </div>
            
            <div class="detail-item">
                <span class="detail-label">Zone ID</span>
                <span class="detail-value">
                    <?php echo !empty($city['zone_id']) ? htmlspecialchars($city['zone_id']) : '<span class="na-text">Not assigned</span>'; ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Additional Information -->
    <?php if (!empty($city['zone_name']) && !empty($city['district_name'])): ?>
    <div class="alert-info">
        <i class="fas fa-info-circle alert-icon"></i>
        <strong>Location Summary:</strong> 
        <?php echo htmlspecialchars($city['city_name']); ?> is located in 
        <?php echo htmlspecialchars($city['district_name']); ?> District, 
        <?php echo htmlspecialchars($city['province']); ?> Province, 
        and belongs to the <?php echo htmlspecialchars($city['zone_name']); ?> zone 
        (<?php echo htmlspecialchars($city['zone_type']); ?> area).
    </div>
    <?php endif; ?>
</div>

<?php
// Close database connection
$stmt->close();
$conn->close();
?>
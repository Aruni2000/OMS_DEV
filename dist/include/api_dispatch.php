<div class="modal-overlay" id="apiDispatchModal" style="display: none;">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-cloud me-2"></i>API Dispatch Orders
            </h3>
            <button class="modal-close" onclick="closeApiDispatchModal()" type="button">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="api-dispatch-form">
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    Dispatching these orders will create API parcels and update order statuses.
                </div>

                <!-- Selected Orders Display -->
                <div class="form-group mb-3">
                    <label class="form-label">Selected Orders (<span id="apiSelectedCount">0</span>)</label>
                    <div class="selected-orders-container" id="apiSelectedOrdersList">
                        <!-- Selected orders will be displayed here -->
                    </div>
                </div>

            <!-- Courier Selection (only show couriers with API integration) -->
<div class="form-group mb-3">
    <label for="api_carrier" class="form-label">Courier Service <span class="text-danger">*</span></label>
    <select class="form-control" id="api_carrier" name="api_carrier" required>
        <option value="" selected disabled>Select API courier service</option>
        <?php
        // Fetch only couriers with API integration (either has_api_new = 1 OR has_api_existing = 1)
        $api_courier_query = "SELECT courier_id, courier_name FROM couriers 
                             WHERE status = 'active' 
                             AND (has_api_new = 1 OR has_api_existing = 1) 
                             ORDER BY courier_name";
        $api_courier_result = $conn->query($api_courier_query);
        
        if ($api_courier_result && $api_courier_result->num_rows > 0) {
            while($courier = $api_courier_result->fetch_assoc()): 
        ?>
            <option value="<?php echo $courier['courier_id']; ?>"><?php echo htmlspecialchars($courier['courier_name']); ?></option>
        <?php 
            endwhile;
        } else {
            echo '<option value="" disabled>No API couriers available</option>';
        }
        ?>
    </select>
    <small class="form-text text-muted">All selected orders will be dispatched with this API courier service</small>
</div>
                
                <!-- Dispatch Type Selection -->
                <div class="form-group mb-3">
                    <label class="form-label">Dispatch Type <span class="text-danger">*</span></label>
                    <div class="dispatch-type-options">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="api_dispatch_type" id="newParcel" value="new" checked>
                            <label class="form-check-label" for="newParcel">
                                <i class="fas fa-plus-circle text-primary me-1"></i> Create New Parcel
                            </label>
                            <small class="d-block text-muted">Create new parcels via API for each order</small>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="radio" name="api_dispatch_type" id="existingParcel" value="existing">
                            <label class="form-check-label" for="existingParcel">
                                <i class="fas fa-truck text-success me-1"></i> Use Existing Parcel
                            </label>
                            <small class="d-block text-muted">Assign existing tracking numbers to orders</small>
                        </div>
                    </div>
                </div>
                
                <!-- Tracking Numbers Section (shown when existing parcel is selected) -->
                <div class="form-group mb-3" id="existingTrackingSection" style="display: none;">
                    <label class="form-label">Tracking Numbers</label>
                    <div class="tracking-preview" id="api_tracking_numbers_display">
                        <span class="text-muted">Select a courier to see available tracking numbers</span>
                    </div>
                    <small class="form-text text-muted">Available tracking numbers will be assigned to each order</small>
                </div>
                
                <!-- API Dispatch Notes -->
                <div class="form-group mb-3">
                    <label for="api_dispatch_notes" class="form-label">Dispatch Notes</label>
                    <textarea class="form-control" id="api_dispatch_notes" name="api_dispatch_notes" rows="3" 
                              placeholder="Enter notes that will be applied to all dispatched orders (optional)"></textarea>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex !important; justify-content: flex-end; padding: 15px; background: #f8f9fa; border-top: 1px solid #ddd;">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeApiDispatchModal()" 
                        style="display: inline-flex !important; padding: 8px 16px; background: #6c757d !important; color: white !important; border: none; border-radius: 4px; margin-right: 10px;">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="api-dispatch-submit-btn" disabled
                        style="display: inline-flex !important; padding: 8px 16px; background: #007bff !important; color: white !important; border: none; border-radius: 4px;">
                    <i class="fas fa-cloud-upload-alt me-1"></i>Confirm API Dispatch
                </button>
            </div>
        </form>
    </div>
</div>
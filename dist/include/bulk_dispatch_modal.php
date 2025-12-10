<div class="modal-overlay" id="bulkDispatchModal" style="display: none;">
    <div class="modal-container">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-truck me-2"></i>Bulk Dispatch Orders
            </h3>
            <button class="modal-close" onclick="closeBulkDispatchModal()" type="button">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="bulk-dispatch-form">
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    Dispatching these orders will assign tracking numbers and update order statuses.
                </div>

                <!-- Selected Orders Display -->
                <div class="form-group mb-3">
                    <label class="form-label">Selected Orders (<span id="bulkSelectedCount">0</span>)</label>
                    <div class="selected-orders-container" id="selectedOrdersList">
                        <!-- Selected orders will be displayed here -->
                    </div>
                </div>

                <!-- Courier Selection -->
                <div class="form-group mb-3">
                    <label for="bulk_carrier" class="form-label">Courier Service <span class="text-danger">*</span></label>
                    <select class="form-control" id="bulk_carrier" name="bulk_carrier" required>
                        <option value="" selected disabled>Select courier service</option>
                        <?php
                        // Fetch active couriers from the database
                        $courier_query = "SELECT courier_id, courier_name FROM couriers WHERE status = 'active' ORDER BY courier_name";
                        $courier_result = $conn->query($courier_query);
                        
                        if ($courier_result && $courier_result->num_rows > 0) {
                            while($courier = $courier_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $courier['courier_id']; ?>"><?php echo htmlspecialchars($courier['courier_name']); ?></option>
                        <?php 
                            endwhile;
                        } else {
                            echo '<option value="" disabled>No couriers available</option>';
                        }
                        ?>
                    </select>
                    <small class="form-text text-muted">All selected orders will be dispatched with this courier service</small>
                </div>
                
                <!-- Tracking Numbers Preview -->
                <div class="form-group mb-3">
                    <label class="form-label">Tracking Numbers</label>
                    <div class="tracking-preview" id="bulk_tracking_numbers_display">
                        <span class="text-muted">Select a courier to see available tracking numbers</span>
                    </div>
                    <small class="form-text text-muted">Available tracking numbers will be assigned to each order</small>
                </div>
                
                <!-- Bulk Dispatch Notes -->
                <div class="form-group mb-3">
                    <label for="bulk_dispatch_notes" class="form-label">Dispatch Notes</label>
                    <textarea class="form-control" id="bulk_dispatch_notes" name="bulk_dispatch_notes" rows="3" 
                              placeholder="Enter notes that will be applied to all dispatched orders (optional)"></textarea>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex !important; justify-content: flex-end; padding: 15px; background: #f8f9fa; border-top: 1px solid #ddd;">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeBulkDispatchModal()" 
                        style="display: inline-flex !important; padding: 8px 16px; background: #6c757d !important; color: white !important; border: none; border-radius: 4px; margin-right: 10px;">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="bulk-dispatch-submit-btn" disabled
                        style="display: inline-flex !important; padding: 8px 16px; background: #007bff !important; color: white !important; border: none; border-radius: 4px;">
                    <i class="fas fa-truck me-1"></i>Confirm Bulk Dispatch
                </button>
            </div>
        </form>
    </div>
</div>
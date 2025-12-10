<!-- ANSWER STATUS MODAL -->
<div class="modal-overlay" id="answerStatusModal" style="display: none;">
    <div class="modal-container-answer">
        <div class="modal-header">
            <h3 class="modal-title" id="answerModalTitle">
                <i class="fas fa-phone me-2"></i>Update Call Status
            </h3>
            <button class="modal-close" onclick="closeAnswerModal()" type="button">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="answer-status-form">
            <!-- Hidden fields to store order info -->
            <input type="hidden" name="order_id" id="answer_order_id">
            <input type="hidden" name="current_call_log" id="current_call_log">
            <input type="hidden" name="new_call_log" id="new_call_log">
            
            <div class="modal-body">
                <!-- Dynamic alert message based on action -->
                <div class="alert" id="answerAlertMessage">
                    <i class="fas fa-info-circle me-2"></i>
                    <span id="alertText">Update call status for this order</span>
                </div>
                
                <!-- Order information display -->
                <div class="order-info-section mb-3">
                    <div class="info-row">
                        <strong>Order ID:</strong> 
                        <span id="displayOrderId">-</span>
                    </div>
                </div>
                
                <!-- Reason/Notes textarea -->
                <div class="form-group mb-3">
                    <label for="answer_reason" class="form-label" id="reasonLabel">
                        Call Notes <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="answer_reason" name="answer_reason" rows="4" 
                              placeholder="Enter call notes or reason..." required></textarea>
                    <small class="form-text text-muted" id="reasonHelp">
                        Please provide details about the call interaction
                    </small>
                </div>
            </div>
            
            <div class="modal-footer" style="display: flex !important; justify-content: flex-end; padding: 15px; background: #f8f9fa; border-top: 1px solid #ddd;">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeAnswerModal()" 
                        style="display: inline-flex !important; padding: 8px 16px; background: #6c757d !important; color: white !important; border: none; border-radius: 4px; margin-right: 10px;">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <button type="submit" class="modal-btn modal-btn-primary" id="answer-submit-btn"
                        style="display: inline-flex !important; padding: 8px 16px; background: #007bff !important; color: white !important; border: none; border-radius: 4px;">
                    <i class="fas fa-check me-1"></i>
                    <span id="submitButtonText">Update Status</span>
                </button>
            </div>
        </form>
    </div>
</div>
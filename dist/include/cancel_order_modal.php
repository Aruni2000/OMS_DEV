<!-- Cancel Order Modal - Replace your existing modal with this -->
<div id="cancelModal" class="modal" style="display: none;">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle text-danger me-2"></i>
                    Cancel Order
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> This action cannot be undone. The order will be permanently cancelled.
                </div>
                
                <p class="mb-3">Are you sure you want to cancel this order?</p>
                
                <div class="form-group">
                    <label for="cancellationReason" class="form-label">
                        Cancellation Reason <span class="text-danger">*</span>
                    </label>
                    <textarea id="cancellationReason" 
                              class="form-control" 
                              rows="4" 
                              placeholder="Please provide a detailed reason for cancellation (minimum 10 characters)..."
                              required></textarea>
                    <small class="form-text text-muted">
                        This reason will be logged for record keeping purposes.
                    </small>
                </div>
            </div>
           <div class="modal-button-group" style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">
            <i class="fas fa-arrow-left me-1"></i>
            No, Keep Order
        </button>
        <button type="button" class="btn btn-danger" id="confirmCancelBtn">
            <i class="fas fa-trash me-1"></i>
            Yes, Cancel Order
        </button>
    </div>
        </div>
    </div>
</div>
<!-- Modal for Marking Order as Paid -->
<div class="modal" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="markPaidModalLabel">
                    <i class="fas fa-credit-card me-2"></i>Payment Upload
                </h5>
                <button type="button" class="btn-close" onclick="closePaidModal()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="markPaidForm" enctype="multipart/form-data">
                    <input type="hidden" name="order_id" id="modal_order_id">

                    <div class="mb-4">
                        <div class="alert alert-info" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            Please upload your payment slip to mark this order as paid.
                        </div>
                    </div>

                    <div class="upload-zone">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        </div>
                        <h6>Upload Payment Slip</h6>
                        <p class="text-muted mb-3">Drag and drop your file here or click to browse</p>
                        
                        <input type="file" class="form-control" id="payment_slip" name="payment_slip"
                            accept=".jpg,.jpeg,.png,.pdf" required style="display: none;">
                        
                        <button type="button" class="btn btn-primary" onclick="document.getElementById('payment_slip').click()">
                            <i class="fas fa-upload me-2"></i>Choose File
                        </button>
                        
                        <div class="form-text mt-3">
                            <i class="fas fa-file-alt me-1"></i>Supported: JPG, JPEG, PNG, PDF (Max: 2MB)
                        </div>
                    </div>

                    <div id="fileInfo" class="file-info" style="display: none;">
                        <i class="fas fa-file-check me-2"></i>
                        <span id="fileName"></span>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeFile()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div class="text-center mt-4">
                        <button type="button" class="btn btn-secondary me-3" onclick="closePaidModal()">
                            <i class="fas fa-times me-1"></i>Cancel
                        </button>
                        <button type="submit" class="btn btn-success" id="submitPaidBtn">
                            <i class="fas fa-check me-1"></i>Mark as Paid
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
    
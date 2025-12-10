  <div id="orderModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h3 class="modal-title">Order Details</h3>
                <button class="modal-close" onclick="closeOrderModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="modalContent">
                <div class="modal-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    Loading order details...
                </div>
            </div>
            <div class="modal-footer">
                <!-- Payment Slip View Button  -->
                <button class="modal-btn modal-btn-info" onclick="viewPaymentSlip()" id="viewPaymentSlipBtn" style="display:none;">
                    <i class="fas fa-file-image"></i>
                    View Payment Slip
                </button>
                
                <button class="modal-btn modal-btn-secondary" onclick="closeOrderModal()">Close</button>
                <button class="modal-btn modal-btn-primary" onclick="downloadOrder()" id="downloadBtn" style="display:none;">
                    <i class="fas fa-download"></i>
                    Download
                </button>
            </div>
        </div>
    </div>

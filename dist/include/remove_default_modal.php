 <!-- Remove Default Confirmation Modal -->
    <div id="removeDefaultModal" class="modal confirmation-modal">
        <div class="modal-content confirmation-modal-content">
            <div class="modal-header">
                <h4>Remove Default Courier</h4>
                <span class="close" onclick="closeModal('removeDefaultModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">
                    <i class="fas fa-star-half-alt" style="color: #6c757d;"></i>
                </div>
                <div class="confirmation-text">
                    Do you want to remove this courier as the default courier?
                </div>
                <div class="confirmation-text">
                    <span class="user-name-highlight" id="remove-default-courier-name"></span>
                </div>
                <div class="modal-buttons">
                    <button class="btn-confirm" id="confirmRemoveDefaultBtn">
                        <span>Yes, remove default!</span>
                    </button>
                    <button class="btn-cancel" onclick="closeModal('removeDefaultModal')">Cancel</button>
                </div>
            </div>
        </div>
    </div>

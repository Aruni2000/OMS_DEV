 <!-- Set Default Confirmation Modal -->
    <div id="setDefaultModal" class="modal confirmation-modal">
        <div class="modal-content confirmation-modal-content">
            <div class="modal-header">
                <h4>Set Default Courier</h4>
                <span class="close" onclick="closeModal('setDefaultModal')">&times;</span>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">
                    <i class="fas fa-star" style="color: #ffc107;"></i>
                </div>
                <div class="confirmation-text">
                    Do you want to set this courier as the default courier?
                </div>
                <div class="confirmation-text">
                    <span class="user-name-highlight" id="set-default-courier-name"></span>
                </div>
                <div class="modal-buttons">
                    <button class="btn-confirm" id="confirmSetDefaultBtn">
                        <span>Yes, set as default!</span>
                    </button>
                    <button class="btn-cancel" onclick="closeModal('setDefaultModal')">Cancel</button>
                </div>
            </div>
        </div>
    </div>
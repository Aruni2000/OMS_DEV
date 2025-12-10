 <!-- API Parcel Modal -->
    <div id="apiParcelModal" class="api-parcel-modal">
        <div class="api-parcel-modal-content">
            <div class="api-parcel-modal-header">
                <h4 class="api-parcel-modal-title">API Parcel Configuration</h4>
                <button class="api-parcel-close" onclick="closeApiParcelModal()">&times;</button>
            </div>
            <div class="api-parcel-modal-body">
                <div class="api-parcel-info">
                    <i class="fas fa-info-circle api-parcel-info-icon"></i>
                    <p class="api-parcel-info-text">
                        API Parcel allows you to create a special courier configuration that integrates with external shipping services through API connections. This will enable automated parcel processing and tracking with third-party logistics providers.
                    </p>
                </div>
                <p><strong>Selected Courier:</strong> <span id="api-parcel-courier-name"></span></p>
            </div>
            <div class="api-parcel-modal-footer">
                <button class="api-parcel-btn api-parcel-btn-cancel" onclick="closeApiParcelModal()">Cancel</button>
                <button class="api-parcel-btn api-parcel-btn-confirm" id="confirmApiParcelBtn" onclick="createApiParcel()">Proceed</button>
            </div>
        </div>
    </div>
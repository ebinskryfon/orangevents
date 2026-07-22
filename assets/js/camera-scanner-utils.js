/**
 * Orange Events - Camera & Barcode Scanner Utility Engine
 * Supports direct product photo capture from webcam/mobile camera
 * & high-accuracy 1D/2D barcode scanning via Html5Qrcode / Web APIs.
 */

window.OrangeCameraUtils = (function () {
    let currentVideoStream = null;
    let html5QrcodeInstance = null;
    let lastScannedCode = null;
    let lastScanTime = 0;
    const SCAN_DEBOUNCE_MS = 1200;

    /**
     * Web Audio API Synthesizer - POS Scanner Chime (no external MP3 needed)
     */
    function playBeepSound(type = 'success') {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            const ctx = new AudioContext();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.connect(gain);
            gain.connect(ctx.destination);

            if (type === 'success') {
                osc.type = 'sine';
                osc.frequency.setValueAtTime(1400, ctx.currentTime);
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.12);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.12);
            } else if (type === 'error') {
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(350, ctx.currentTime);
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.25);
            }
    /**
     * Clean & sanitize raw barcode/QR code scanned output.
     * Removes AIM Symbology Identifiers (ISO/IEC 15424 e.g. ]c, ]C1, ]Q1, ]d2, ]E0),
     * control characters, zero-width characters, and extraneous whitespace.
     */
    function cleanBarcode(val) {
        if (val === null || val === undefined) return '';
        let str = String(val);

        // Strip non-printable ASCII control chars (0x00-0x1F, 0x7F-0x9F, zero-width space, BOM)
        str = str.replace(/[\x00-\x1F\x7F-\x9F\u200B-\u200D\uFEFF]/g, '');
        str = str.trim();

        // Strip AIM Symbology Identifier prefixes starting with ']' (ISO/IEC 15424)
        if (str.startsWith(']')) {
            // Pattern 1: ']' + 1-4 letters/digits followed by whitespace (e.g. "]c   1234", "]C1  1234")
            str = str.replace(/^\][A-Za-z0-9]{1,4}\s+/, '');

            // Pattern 2: Standard 3-character AIM identifier (e.g. "]C1", "]Q1", "]d2", "]E0", "]A0")
            if (str.startsWith(']')) {
                str = str.replace(/^\][A-Za-z][0-9A-Za-z]/, '');
            }

            // Pattern 3: 2-character AIM identifier (e.g. "]c", "]Q", "]d")
            if (str.startsWith(']')) {
                str = str.replace(/^\][A-Za-z]/, '');
            }

            // Fallback: strip any remaining leading ']' and leading spaces
            if (str.startsWith(']')) {
                str = str.replace(/^\]+\s*/, '');
            }

            str = str.trim();
        }

        return str;
    }


    /**
     * Helper to stop any active video stream
     */
    function stopActiveVideoStream() {
        if (currentVideoStream) {
            currentVideoStream.getTracks().forEach(track => track.stop());
            currentVideoStream = null;
        }
    }

    /**
     * Helper to stop Html5Qrcode instance
     */
    async function stopQrcodeInstance() {
        if (html5QrcodeInstance) {
            try {
                if (html5QrcodeInstance.isScanning) {
                    await html5QrcodeInstance.stop();
                }
                html5QrcodeInstance.clear();
            } catch (e) {
                console.warn('Error clearing Html5Qrcode instance:', e);
            }
            html5QrcodeInstance = null;
        }
    }

    /**
     * Create base modal element if it doesn't exist
     */
    function getOrCreateModalContainer(id, titleHtml) {
        let modal = document.getElementById(id);
        if (!modal) {
            modal = document.createElement('div');
            modal.id = id;
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
                background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(8px);
                z-index: 10500; display: flex; align-items: center; justify-content: center;
                padding: 1rem; opacity: 0; transition: opacity 0.25s ease;
            `;
            modal.innerHTML = `
                <div style="background: var(--bg-card, #1e293b); color: var(--text-primary, #f8fafc);
                            border: 1px solid var(--border-color, #334155); border-radius: 12px;
                            width: 100%; max-width: 420px; padding: 1rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);
                            display: flex; flex-direction: column; gap: 0.75rem; position: relative;">
                    <div style="display:flex; align-items:center; justify-content:space-between; border-bottom: 1px solid var(--border-color, #334155); padding-bottom: 0.5rem;">
                        <h3 style="margin: 0; font-size: 1rem; display: flex; align-items: center; gap: 0.5rem;" id="${id}_title">
                            ${titleHtml}
                        </h3>
                        <button type="button" class="${id}_close_btn" style="background: none; border: none; color: var(--text-muted, #94a3b8); font-size: 1.25rem; cursor: pointer; padding: 0.25rem 0.5rem; border-radius: 4px;">&times;</button>
                    </div>
                    <div id="${id}_content" style="display:flex; flex-direction:column; gap:0.75rem; align-items:center;"></div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        return modal;
    }

    /**
     * Feature A: Product Image Camera Capture Modal
     */
    function openProductCameraModal(onCapturedCallback) {
        stopActiveVideoStream();
        const modal = getOrCreateModalContainer('orange_product_cam_modal', '<i class="fa-solid fa-camera" style="color:var(--accent-color, #ff5e00);"></i> Capture Product Photo');
        const content = modal.querySelector('#orange_product_cam_modal_content');

        content.innerHTML = `
            <div style="position: relative; width: 100%; max-width: 320px; aspect-ratio: 1/1; background: #000; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center;">
                <video id="orange_cam_video" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                <canvas id="orange_cam_canvas" style="display: none;"></canvas>
                <img id="orange_cam_preview" style="display: none; width: 100%; height: 100%; object-fit: cover;">
                <!-- Reticle frame -->
                <div id="orange_cam_reticle" style="position: absolute; inset: 15px; border: 2px dashed rgba(255,255,255,0.6); border-radius: 8px; pointer-events: none; box-shadow: 0 0 0 9999px rgba(0,0,0,0.3);"></div>
            </div>

            <div style="display: flex; gap: 0.5rem; width: 100%; justify-content: center; align-items: center;">
                <select id="orange_cam_device_select" class="form-control" style="max-width: 220px; font-size: 0.85rem; padding: 0.4rem; display: none;"></select>
                <button type="button" id="orange_cam_switch_btn" class="btn btn-secondary btn-sm" style="display: none;">
                    <i class="fa-solid fa-camera-rotate"></i> Switch Camera
                </button>
            </div>

            <div style="display: flex; gap: 0.75rem; width: 100%; justify-content: flex-end; border-top: 1px solid var(--border-color, #334155); padding-top: 0.75rem;">
                <button type="button" id="orange_cam_cancel_btn" class="btn btn-secondary">Cancel</button>
                <button type="button" id="orange_cam_retake_btn" class="btn btn-secondary" style="display: none;">
                    <i class="fa-solid fa-rotate-left"></i> Retake
                </button>
                <button type="button" id="orange_cam_snap_btn" class="btn btn-primary" style="background: var(--accent-color, #ff5e00); border: none;">
                    <i class="fa-solid fa-circle-dot"></i> Snap Photo
                </button>
                <button type="button" id="orange_cam_use_btn" class="btn btn-success" style="display: none;">
                    <i class="fa-solid fa-check"></i> Use Photo
                </button>
            </div>
        `;

        modal.style.display = 'flex';
        requestAnimationFrame(() => modal.style.opacity = '1');

        const video = content.querySelector('#orange_cam_video');
        const canvas = content.querySelector('#orange_cam_canvas');
        const previewImg = content.querySelector('#orange_cam_preview');
        const reticle = content.querySelector('#orange_cam_reticle');
        const deviceSelect = content.querySelector('#orange_cam_device_select');
        const switchBtn = content.querySelector('#orange_cam_switch_btn');
        const snapBtn = content.querySelector('#orange_cam_snap_btn');
        const useBtn = content.querySelector('#orange_cam_use_btn');
        const retakeBtn = content.querySelector('#orange_cam_retake_btn');
        const cancelBtn = content.querySelector('#orange_cam_cancel_btn');
        const closeBtn = modal.querySelector('.orange_product_cam_modal_close_btn');

        let capturedBlob = null;
        let selectedDeviceId = null;
        let videoDevices = [];

        async function startCamera(deviceId = null) {
            stopActiveVideoStream();
            try {
                const constraints = {
                    video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' }, width: { ideal: 1080 }, height: { ideal: 1080 } },
                    audio: false
                };
                currentVideoStream = await navigator.mediaDevices.getUserMedia(constraints);
                video.srcObject = currentVideoStream;
                await video.play();

                // Enumerate cameras if not already done
                if (videoDevices.length === 0) {
                    const devices = await navigator.mediaDevices.enumerateDevices();
                    videoDevices = devices.filter(d => d.kind === 'videoinput');
                    if (videoDevices.length > 1) {
                        deviceSelect.innerHTML = videoDevices.map((d, i) => `<option value="${d.deviceId}">Camera ${i + 1} (${d.label || 'Default'})</option>`).join('');
                        deviceSelect.style.display = 'inline-block';
                        switchBtn.style.display = 'inline-block';
                    }
                }
            } catch (err) {
                console.error('Camera access error:', err);
                alert('Could not access camera: ' + (err.message || 'Permission denied or no camera device available.'));
                closeModal();
            }
        }

        function closeModal() {
            modal.style.opacity = '0';
            setTimeout(() => {
                modal.style.display = 'none';
                stopActiveVideoStream();
            }, 250);
        }

        // Camera switching handlers
        deviceSelect.addEventListener('change', (e) => {
            selectedDeviceId = e.target.value;
            startCamera(selectedDeviceId);
        });

        switchBtn.addEventListener('click', () => {
            if (videoDevices.length > 1) {
                const currentIndex = videoDevices.findIndex(d => d.deviceId === selectedDeviceId);
                const nextIndex = (currentIndex + 1) % videoDevices.length;
                selectedDeviceId = videoDevices[nextIndex].deviceId;
                deviceSelect.value = selectedDeviceId;
                startCamera(selectedDeviceId);
            }
        });

        // Snap photo handler
        snapBtn.addEventListener('click', () => {
            if (!video.videoWidth) return;
            playBeepSound('success');

            // Draw current frame to square canvas
            const side = Math.min(video.videoWidth, video.videoHeight);
            const sx = (video.videoWidth - side) / 2;
            const sy = (video.videoHeight - side) / 2;

            canvas.width = 600;
            canvas.height = 600;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, sx, sy, side, side, 0, 0, 600, 600);

            canvas.toBlob((blob) => {
                capturedBlob = blob;
                const url = URL.createObjectURL(blob);
                previewImg.src = url;
                video.style.display = 'none';
                previewImg.style.display = 'block';
                reticle.style.display = 'none';

                snapBtn.style.display = 'none';
                useBtn.style.display = 'inline-block';
                retakeBtn.style.display = 'inline-block';
            }, 'image/jpeg', 0.92);
        });

        // Retake photo handler
        retakeBtn.addEventListener('click', () => {
            previewImg.style.display = 'none';
            video.style.display = 'block';
            reticle.style.display = 'block';

            snapBtn.style.display = 'inline-block';
            useBtn.style.display = 'none';
            retakeBtn.style.display = 'none';
            capturedBlob = null;
        });

        // Use photo handler
        useBtn.addEventListener('click', () => {
            if (capturedBlob && onCapturedCallback) {
                const capturedFile = new File([capturedBlob], `camera_photo_${Date.now()}.jpg`, { type: 'image/jpeg' });
                onCapturedCallback(capturedFile, URL.createObjectURL(capturedBlob));
            }
            closeModal();
        });

        cancelBtn.addEventListener('click', closeModal);
        closeBtn.addEventListener('click', closeModal);

        startCamera();
    }

    /**
     * Feature B: Single Barcode Scan Modal (for adding/editing variant barcode)
     */
    function openBarcodeScanModal(onScannedCallback) {
        if (typeof Html5Qrcode === 'undefined') {
            alert('Barcode scanner library (Html5Qrcode) is loading or unavailable. Please refresh the page.');
            return;
        }

        stopActiveVideoStream();
        const modal = getOrCreateModalContainer('orange_barcode_scan_modal', '<i class="fa-solid fa-barcode" style="color:var(--accent-color, #ff5e00);"></i> Scan Barcode with Camera');
        const content = modal.querySelector('#orange_barcode_scan_modal_content');

        content.innerHTML = `
            <div id="orange_barcode_reader" style="width: 100%; max-width: 340px; aspect-ratio: 4/3; background: #000; border-radius: 8px; overflow: hidden; position: relative;"></div>
            <div style="font-size: 0.8rem; color: var(--text-muted, #94a3b8); text-align: center;">
                Position barcode within the frame. Auto-detects 1D & 2D barcodes.
            </div>
            <div style="display: flex; gap: 0.75rem; width: 100%; justify-content: flex-end; border-top: 1px solid var(--border-color, #334155); padding-top: 0.75rem;">
                <button type="button" id="orange_barcode_cancel_btn" class="btn btn-secondary">Close Scanner</button>
            </div>
        `;

        modal.style.display = 'flex';
        requestAnimationFrame(() => modal.style.opacity = '1');

        const cancelBtn = content.querySelector('#orange_barcode_cancel_btn');
        const closeBtn = modal.querySelector('.orange_barcode_scan_modal_close_btn');

        const html5Qrcode = new Html5Qrcode('orange_barcode_reader');
        html5QrcodeInstance = html5Qrcode;

        async function closeModal() {
            modal.style.opacity = '0';
            await stopQrcodeInstance();
            setTimeout(() => {
                modal.style.display = 'none';
            }, 250);
        }

        cancelBtn.addEventListener('click', closeModal);
        closeBtn.addEventListener('click', closeModal);

        const config = {
            fps: 15,
            qrbox: { width: 280, height: 160 },
            aspectRatio: 1.333333
        };

        html5Qrcode.start(
            { facingMode: 'environment' },
            config,
            (decodedText, decodedResult) => {
                const cleanedText = cleanBarcode(decodedText);
                playBeepSound('success');
                if (onScannedCallback) {
                    onScannedCallback(cleanedText, decodedResult, decodedText);
                }
                closeModal();
            },
            (errorMessage) => {
                // Parse errors are expected while searching for barcode frame
            }
        ).catch(err => {
            console.error('Html5Qrcode scanner failed to start:', err);
            alert('Could not start camera scanner: ' + (err.message || 'Camera permission denied or unavailable.'));
            closeModal();
        });
    }

    /**
     * Feature C: Embedded POS Continuous Camera Scanner Widget
     */
    function initPosCameraScanner(containerId, onScanCallback) {
        const container = document.getElementById(containerId);
        if (!container) return null;

        if (typeof Html5Qrcode === 'undefined') {
            container.innerHTML = `<div class="alert alert-warning" style="font-size:0.8rem;">Barcode scanner library loading... Please wait.</div>`;
            return null;
        }

        container.innerHTML = `
            <div style="background: var(--bg-card, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: 8px; padding: 0.75rem; display: flex; flex-direction: column; gap: 0.5rem;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                        <i class="fa-solid fa-camera" style="color: var(--accent-color, #ff5e00);"></i> Live Camera Scanner
                    </div>
                    <button type="button" id="pos_cam_toggle_btn" class="btn btn-sm btn-outline-primary" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">
                        <i class="fa-solid fa-power-off"></i> Start Camera
                    </button>
                </div>
                <div id="pos_cam_reader" style="width: 100%; aspect-ratio: 4/3; background: #000; border-radius: 6px; overflow: hidden; display: none; position: relative;"></div>
                <div id="pos_cam_status" style="font-size: 0.72rem; color: var(--text-muted); text-align: center;">Camera scanner idle. Click "Start Camera" to scan items.</div>
            </div>
        `;

        const toggleBtn = container.querySelector('#pos_cam_toggle_btn');
        const readerDiv = container.querySelector('#pos_cam_reader');
        const statusDiv = container.querySelector('#pos_cam_status');

        let isRunning = false;
        let posScannerInstance = null;

        toggleBtn.addEventListener('click', async () => {
            if (isRunning) {
                // Stop camera
                if (posScannerInstance) {
                    try {
                        await posScannerInstance.stop();
                        posScannerInstance.clear();
                    } catch (e) {}
                    posScannerInstance = null;
                }
                readerDiv.style.display = 'none';
                statusDiv.textContent = 'Camera scanner stopped.';
                toggleBtn.classList.remove('btn-danger');
                toggleBtn.classList.add('btn-outline-primary');
                toggleBtn.innerHTML = `<i class="fa-solid fa-power-off"></i> Start Camera`;
                isRunning = false;
            } else {
                // Start camera
                readerDiv.style.display = 'block';
                statusDiv.textContent = 'Initializing camera stream...';
                posScannerInstance = new Html5Qrcode('pos_cam_reader');

                const config = {
                    fps: 15,
                    qrbox: { width: 260, height: 150 },
                    aspectRatio: 1.333333
                };

                try {
                    await posScannerInstance.start(
                        { facingMode: 'environment' },
                        config,
                        (decodedText) => {
                            const cleanedText = cleanBarcode(decodedText);
                            const now = Date.now();
                            if (cleanedText === lastScannedCode && (now - lastScanTime) < SCAN_DEBOUNCE_MS) {
                                return; // Ignore duplicate scan within debounce timeframe
                            }
                            lastScannedCode = cleanedText;
                            lastScanTime = now;

                            playBeepSound('success');
                            const safeDisplay = (typeof escapeHtml === 'function') ? escapeHtml(cleanedText) : cleanedText;
                            statusDiv.innerHTML = `<span style="color:#2ed573; font-weight:600;"><i class="fa-solid fa-check"></i> Scanned: ${safeDisplay}</span>`;

                            if (onScanCallback) {
                                onScanCallback(cleanedText, decodedText);
                            }
                        },
                        () => {}
                    );
                    isRunning = true;
                    statusDiv.textContent = 'Camera active. Point barcode into box.';
                    toggleBtn.classList.remove('btn-outline-primary');
                    toggleBtn.classList.add('btn-danger');
                    toggleBtn.innerHTML = `<i class="fa-solid fa-stop"></i> Stop Camera`;
                } catch (err) {
                    console.error('POS camera start error:', err);
                    statusDiv.innerHTML = `<span style="color:#ff4757;">Failed to access camera: ${err.message || 'Denied/Unavailable'}</span>`;
                    readerDiv.style.display = 'none';
                }
            }
        });

        return {
            stop: async () => {
                if (posScannerInstance && isRunning) {
                    try {
                        await posScannerInstance.stop();
                        posScannerInstance.clear();
                    } catch (e) {}
                }
            }
        };
    }

    return {
        playBeepSound: playBeepSound,
        cleanBarcode: cleanBarcode,
        openProductCameraModal: openProductCameraModal,
        openBarcodeScanModal: openBarcodeScanModal,
        initPosCameraScanner: initPosCameraScanner
    };
})();

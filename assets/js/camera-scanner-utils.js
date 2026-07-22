/**
 * Orange Events - Camera & Barcode Scanner Utility Engine
 * Supports direct product photo capture from webcam/mobile camera
 * & high-accuracy 1D/2D barcode scanning via Html5Qrcode / Web APIs.
 * Optimized for mobile touch, torch control, dynamic aspect ratio, & haptic feedback.
 */

window.OrangeCameraUtils = (function () {
    let currentVideoStream = null;
    let html5QrcodeInstance = null;
    let lastScannedCode = null;
    let lastScanTime = 0;
    let isTorchOn = false;
    const SCAN_DEBOUNCE_MS = 800;

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
        } catch (e) {
            console.warn('Audio play error:', e);
        }
    }

    /**
     * Trigger mobile haptic vibration feedback
     */
    function triggerHaptic(duration = 80) {
        try {
            if (navigator.vibrate) {
                navigator.vibrate(duration);
            }
        } catch (e) {}
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
        str = str.replace(/[\x00-\x1F\x7F-\x9F\u200B-\u200D\uFEFF]/g, '').trim();
        if (!str) return '';

        // ISO/IEC 15424 AIM Symbology Identifiers: ] + 1 letter + optional 1 letter/digit (e.g. ]C1, ]C0, ]c, ]Q1, ]d2, ]E0)
        str = str.replace(/^(?:\][A-Za-z][0-9A-Za-z]?\s*)+/, '');
        str = str.replace(/(?:\s*\][A-Za-z][0-9A-Za-z]?)+$/, '');
        str = str.replace(/\][A-Za-z][0-9A-Za-z]?/g, '');
        str = str.replace(/^\]+|\]+$/g, '').trim();

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
        isTorchOn = false;
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
        isTorchOn = false;
    }

    /**
     * Calculate dynamic QR/Barcode scan box dimensions for responsive mobile viewports
     */
    function calculateQrBox(viewWidth, viewHeight) {
        const minSide = Math.min(viewWidth, viewHeight);
        const boxWidth = Math.floor(Math.min(viewWidth * 0.85, 320));
        const boxHeight = Math.floor(Math.min(viewHeight * 0.6, 200));
        return { width: Math.max(boxWidth, 180), height: Math.max(boxHeight, 120) };
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
                background: rgba(0, 0, 0, 0.88); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
                z-index: 10500; display: flex; align-items: center; justify-content: center;
                padding: 0.75rem; opacity: 0; transition: opacity 0.25s ease;
            `;
            modal.innerHTML = `
                <div style="background: var(--bg-card, #1e293b); color: var(--text-primary, #f8fafc);
                            border: 1px solid var(--border-color, #334155); border-radius: 16px;
                            width: 100%; max-width: 440px; padding: 1rem; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6);
                            display: flex; flex-direction: column; gap: 0.75rem; position: relative; max-height: 90vh; overflow-y: auto;">
                    <div style="display:flex; align-items:center; justify-content:space-between; border-bottom: 1px solid var(--border-color, #334155); padding-bottom: 0.6rem;">
                        <h3 style="margin: 0; font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;" id="${id}_title">
                            ${titleHtml}
                        </h3>
                        <button type="button" class="${id}_close_btn" style="background: rgba(255,255,255,0.08); border: none; color: var(--text-muted, #94a3b8); font-size: 1.4rem; cursor: pointer; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; line-height: 1;">&times;</button>
                    </div>
                    <div id="${id}_content" style="display:flex; flex-direction:column; gap:0.75rem; align-items:center; width:100%;"></div>
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
            <div style="position: relative; width: 100%; max-width: 320px; aspect-ratio: 1/1; background: #000; border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; box-shadow: inset 0 0 10px rgba(0,0,0,0.8);">
                <video id="orange_cam_video" autoplay playsinline style="width: 100%; height: 100%; object-fit: cover;"></video>
                <canvas id="orange_cam_canvas" style="display: none;"></canvas>
                <img id="orange_cam_preview" style="display: none; width: 100%; height: 100%; object-fit: cover;">
                <div id="orange_cam_reticle" style="position: absolute; inset: 15px; border: 2px dashed rgba(255,255,255,0.7); border-radius: 8px; pointer-events: none; box-shadow: 0 0 0 9999px rgba(0,0,0,0.35);"></div>
            </div>

            <div style="display: flex; gap: 0.5rem; width: 100%; justify-content: center; align-items: center; flex-wrap: wrap;">
                <select id="orange_cam_device_select" class="form-control" style="max-width: 220px; font-size: 0.85rem; padding: 0.4rem; display: none;"></select>
                <button type="button" id="orange_cam_switch_btn" class="btn btn-secondary btn-sm" style="display: none;">
                    <i class="fa-solid fa-camera-rotate"></i> Switch Camera
                </button>
            </div>

            <div style="display: flex; gap: 0.75rem; width: 100%; justify-content: flex-end; border-top: 1px solid var(--border-color, #334155); padding-top: 0.75rem;">
                <button type="button" id="orange_cam_cancel_btn" class="btn btn-secondary" style="border-radius: 8px; min-height: 40px;">Cancel</button>
                <button type="button" id="orange_cam_retake_btn" class="btn btn-secondary" style="display: none; border-radius: 8px; min-height: 40px;">
                    <i class="fa-solid fa-rotate-left"></i> Retake
                </button>
                <button type="button" id="orange_cam_snap_btn" class="btn btn-primary" style="background: var(--accent-color, #ff5e00); border: none; border-radius: 8px; min-height: 40px; font-weight: 700;">
                    <i class="fa-solid fa-circle-dot"></i> Snap Photo
                </button>
                <button type="button" id="orange_cam_use_btn" class="btn btn-success" style="display: none; border-radius: 8px; min-height: 40px; font-weight: 700;">
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
                let hintMsg = err.message || 'Permission denied or no camera device available.';
                if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
                    hintMsg += '\n\nNote: Mobile browsers block camera access on non-HTTPS (HTTP) origins when accessed over LAN IP.';
                }
                alert('Could not access camera: ' + hintMsg);
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

        snapBtn.addEventListener('click', () => {
            if (!video.videoWidth) return;
            playBeepSound('success');
            triggerHaptic(60);

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

        retakeBtn.addEventListener('click', () => {
            previewImg.style.display = 'none';
            video.style.display = 'block';
            reticle.style.display = 'block';

            snapBtn.style.display = 'inline-block';
            useBtn.style.display = 'none';
            retakeBtn.style.display = 'none';
            capturedBlob = null;
        });

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
        const modal = getOrCreateModalContainer('orange_barcode_scan_modal', '<i class="fa-solid fa-barcode" style="color:var(--accent-color, #ff5e00);"></i> Scan Barcode / QR');
        const content = modal.querySelector('#orange_barcode_scan_modal_content');

        content.innerHTML = `
            <div id="orange_barcode_reader" style="width: 100%; max-width: 360px; min-height: 240px; background: #000; border-radius: 12px; overflow: hidden; position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.5);"></div>
            <div style="font-size: 0.8rem; color: var(--text-muted, #94a3b8); text-align: center; line-height: 1.3;">
                Position 1D barcode or QR code inside the box. Auto-detects product codes.
            </div>
            <div style="display: flex; gap: 0.75rem; width: 100%; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color, #334155); padding-top: 0.75rem;">
                <button type="button" id="orange_torch_btn" class="btn btn-secondary btn-sm" style="display:none; border-radius: 8px;">
                    <i class="fa-solid fa-bolt"></i> Flashlight
                </button>
                <button type="button" id="orange_barcode_cancel_btn" class="btn btn-secondary" style="border-radius: 8px; margin-left: auto;">Close Scanner</button>
            </div>
        `;

        modal.style.display = 'flex';
        requestAnimationFrame(() => modal.style.opacity = '1');

        const cancelBtn = content.querySelector('#orange_barcode_cancel_btn');
        const torchBtn = content.querySelector('#orange_torch_btn');
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
            fps: 20,
            qrbox: (w, h) => calculateQrBox(w, h),
            aspectRatio: 1.333333
        };

        html5Qrcode.start(
            { facingMode: 'environment' },
            config,
            (decodedText, decodedResult) => {
                const cleanedText = cleanBarcode(decodedText);
                playBeepSound('success');
                triggerHaptic(80);
                if (onScannedCallback) {
                    onScannedCallback(cleanedText, decodedResult, decodedText);
                }
                closeModal();
            },
            () => {}
        ).then(() => {
            // Check torch support
            try {
                const track = html5Qrcode.getRunningTrack();
                if (track && track.getCapabilities && track.getCapabilities().torch) {
                    torchBtn.style.display = 'inline-flex';
                    torchBtn.addEventListener('click', async () => {
                        isTorchOn = !isTorchOn;
                        await track.applyConstraints({ advanced: [{ torch: isTorchOn }] });
                        torchBtn.classList.toggle('btn-warning', isTorchOn);
                    });
                }
            } catch (e) {}
        }).catch(err => {
            console.error('Html5Qrcode scanner failed to start:', err);
            let errText = err.message || 'Camera permission denied or unavailable.';
            if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
                errText += ' (Note: Mobile browsers block camera on non-HTTPS IP origins)';
            }
            alert('Could not start camera scanner: ' + errText);
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
            <div style="background: var(--bg-card, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: 12px; padding: 0.75rem; display: flex; flex-direction: column; gap: 0.6rem; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div style="font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; gap: 0.4rem; color: var(--text-primary);">
                        <i class="fa-solid fa-camera" style="color: var(--accent-color, #ff5e00);"></i> Live Camera Scanner
                    </div>
                    <div style="display: flex; gap: 0.35rem; align-items: center;">
                        <button type="button" id="pos_cam_torch_btn" class="btn btn-sm btn-outline-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; display: none;">
                            <i class="fa-solid fa-bolt"></i>
                        </button>
                        <button type="button" id="pos_cam_toggle_btn" class="btn btn-sm btn-primary" style="padding: 0.3rem 0.65rem; font-size: 0.78rem; font-weight: 700; background: var(--accent-color, #ff5e00); border: none; border-radius: 8px;">
                            <i class="fa-solid fa-power-off"></i> Start Camera
                        </button>
                    </div>
                </div>
                <div id="pos_cam_reader" style="width: 100%; aspect-ratio: 4/3; max-height: 280px; background: #000; border-radius: 8px; overflow: hidden; display: none; position: relative;"></div>
                <div id="pos_cam_status" style="font-size: 0.75rem; color: var(--text-muted); text-align: center; min-height: 1.25rem;">Camera scanner idle. Tap "Start Camera" to scan items.</div>
            </div>
        `;

        const toggleBtn = container.querySelector('#pos_cam_toggle_btn');
        const torchBtn = container.querySelector('#pos_cam_torch_btn');
        const readerDiv = container.querySelector('#pos_cam_reader');
        const statusDiv = container.querySelector('#pos_cam_status');

        let isRunning = false;
        let posScannerInstance = null;

        toggleBtn.addEventListener('click', async () => {
            if (isRunning) {
                if (posScannerInstance) {
                    try {
                        await posScannerInstance.stop();
                        posScannerInstance.clear();
                    } catch (e) {}
                    posScannerInstance = null;
                }
                readerDiv.style.display = 'none';
                torchBtn.style.display = 'none';
                statusDiv.textContent = 'Camera scanner stopped.';
                toggleBtn.classList.remove('btn-danger');
                toggleBtn.classList.add('btn-primary');
                toggleBtn.style.background = 'var(--accent-color, #ff5e00)';
                toggleBtn.innerHTML = `<i class="fa-solid fa-power-off"></i> Start Camera`;
                isRunning = false;
                isTorchOn = false;
            } else {
                readerDiv.style.display = 'block';
                statusDiv.textContent = 'Initializing camera stream...';
                posScannerInstance = new Html5Qrcode('pos_cam_reader');

                const config = {
                    fps: 20,
                    qrbox: (w, h) => calculateQrBox(w, h),
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
                                return;
                            }
                            lastScannedCode = cleanedText;
                            lastScanTime = now;

                            playBeepSound('success');
                            triggerHaptic(80);
                            const safeDisplay = (typeof escapeHtml === 'function') ? escapeHtml(cleanedText) : cleanedText;
                            statusDiv.innerHTML = `<span style="color:#2ed573; font-weight:700;"><i class="fa-solid fa-check-circle"></i> Scanned: ${safeDisplay}</span>`;

                            if (onScanCallback) {
                                onScanCallback(cleanedText, decodedText);
                            }
                        },
                        () => {}
                    );

                    isRunning = true;
                    statusDiv.textContent = 'Camera active. Point barcode/QR code into framing box.';
                    toggleBtn.classList.remove('btn-primary');
                    toggleBtn.classList.add('btn-danger');
                    toggleBtn.style.background = '#ff4757';
                    toggleBtn.innerHTML = `<i class="fa-solid fa-stop"></i> Stop Camera`;

                    // Enable flashlight if available
                    try {
                        const track = posScannerInstance.getRunningTrack();
                        if (track && track.getCapabilities && track.getCapabilities().torch) {
                            torchBtn.style.display = 'inline-flex';
                            torchBtn.onclick = async () => {
                                isTorchOn = !isTorchOn;
                                await track.applyConstraints({ advanced: [{ torch: isTorchOn }] });
                                torchBtn.classList.toggle('btn-warning', isTorchOn);
                            };
                        }
                    } catch (e) {}
                } catch (err) {
                    console.error('POS camera start error:', err);
                    let errStr = err.message || 'Denied/Unavailable';
                    if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
                        errStr += ' (Non-HTTPS IP connections block camera on mobile)';
                    }
                    statusDiv.innerHTML = `<span style="color:#ff4757; font-weight:600;"><i class="fa-solid fa-circle-exclamation"></i> Camera Access Error: ${errStr}</span>`;
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
        triggerHaptic: triggerHaptic,
        cleanBarcode: cleanBarcode,
        openProductCameraModal: openProductCameraModal,
        openBarcodeScanModal: openBarcodeScanModal,
        initPosCameraScanner: initPosCameraScanner
    };
})();

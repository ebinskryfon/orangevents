/**
 * Orange Events - Camera Photo & Barcode Utility Engine
 * Supports direct product photo capture from webcam/mobile camera
 * and barcode input sanitization & sound/haptic feedback.
 */

window.OrangeCameraUtils = (function () {
    let currentVideoStream = null;
    let isTorchOn = false;

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

    return {
        playBeepSound: playBeepSound,
        triggerHaptic: triggerHaptic,
        cleanBarcode: cleanBarcode,
        openProductCameraModal: openProductCameraModal
    };
})();

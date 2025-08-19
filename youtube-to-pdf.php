<?php
/*
Plugin Name: YouTube to PDF
Description: Downloads a YouTube video, extracts frames with ffmpeg (FPS or scene-detect), and generates a PDF. Front-end controls for FPS, scene threshold, max pages, width, and time range.
Version: 1.7
Author: SH
*/

if (!defined('ABSPATH')) exit;

/** Force TCPDF to use a plugin-local cache (fixes Windows TEMP permission issues) */
if (!defined('K_PATH_CACHE')) {
    define('K_PATH_CACHE', plugin_dir_path(__FILE__) . 'tcpdf_cache' . "\\");
}
if (!file_exists(K_PATH_CACHE)) {
    @mkdir(K_PATH_CACHE, 0777, true);
}

class YouTubeToPDF {

    private array  $uploads;
    private string $stamp;
    private string $workDir;     // e.g. .../uploads/ytp_YYYYMMDD_HHMMSS
    private string $workUrl;     // public URL for workDir
    private string $videoFile;   // .../video.mp4
    private string $framesDir;   // .../frames
    private string $yt_dlp;      // resolved yt-dlp path
    private string $ffmpeg;      // resolved ffmpeg path
	private string $cacheDir;
	
    public function __construct() {
		 $this->uploads = wp_upload_dir();
        // In __construct():
		$this->cacheDir = rtrim($this->uploads['basedir'], "\\") . "\\" . 'ytp_cache';
		if (!file_exists($this->cacheDir)) {
			wp_mkdir_p($this->cacheDir);
		}
		$this->videoFile = $this->cacheDir . "\\" . 'video.mp4';
		



        // Resolve executables: prefer plugin-local, else PATH
        $yt_dlp_pref = plugin_dir_path(__FILE__) . 'yt-dlp-master/yt-dlp-master/yt-dlp.exe';
        $ffmpeg_pref = plugin_dir_path(__FILE__) . 'ffmpeg-7.1.1/bin/ffmpeg.exe';
        $this->yt_dlp = $this->resolve_bin($yt_dlp_pref, 'yt-dlp');
        $this->ffmpeg = $this->resolve_bin($ffmpeg_pref, 'ffmpeg');

        add_shortcode('youtube_to_pdf', [$this, 'shortcode_form']);
        add_action('init', [$this, 'handle_form']);
    }

    /** Prefer an explicit path if it exists; else try system PATH */
    private function resolve_bin(string $preferred, string $bin): string {
        if ($preferred && file_exists($preferred)) return $preferred;
        $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which';
        $found = trim(shell_exec("$which $bin 2>NUL"));
        return $found ?: $bin;
    }

    /** Cross-platform safe quoting for paths/URLs (Windows cmd hates single quotes) */
    private function q(string $s): string {
        if (stripos(PHP_OS, 'WIN') === 0) {
            return '"' . str_replace('"', '\"', $s) . '"';
        }
        return escapeshellarg($s);
    }

    /** Double-quote arbitrary string (used for -vf) */
    private function dq(string $s): string {
        return '"' . str_replace('"', '\"', $s) . '"';
    }
	private function reset_cache() {
    if (is_dir($this->framesDir)) {
        $files = glob($this->framesDir . '/*'); // get all files
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    } else {
        mkdir($this->framesDir, 0777, true);
    }
}

    public function shortcode_form() {
        ob_start(); ?>
        <form method="post" style="max-width:720px">
            <p><label>YouTube URL:<br>
                <input type="text" name="yt_url" style="width:100%" required></label>
            </p>

            <fieldset style="border:1px solid #ddd;padding:12px;border-radius:8px">
                <legend><strong>Frame Extraction Options</strong></legend>

                <p>
                    <label>Mode:</label><br>
                    <label><input type="radio" name="mode" value="scene" checked> Scene detection</label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="mode" value="fps"> Fixed FPS</label>
                </p>

                <div id="scene_opts">
                    <label>Scene Threshold (0.05–1, smaller = more frames):</label><br>
                    <input type="number" name="scene" step="0.01" min="0.01" max="1" value="0.20">
                </div>

                <div id="fps_opts" style="margin-top:8px">
                    <label>FPS (0 = disabled):</label><br>
                    <input type="number" name="fps" min="0" step="1" value="0">
                </div>

                <p style="margin-top:8px">
                    <label>Image Width (px):</label><br>
                    <input type="number" name="width" min="200" step="10" value="800">
                </p>

                <p>
                    <label>Max Pages:</label><br>
                    <input type="number" name="max_pages" min="1" step="1" value="200">
                </p>

                <p>
                    <label>Start Time (seconds):</label><br>
                    <input type="number" name="start_time" min="0" step="1" value="0">
                </p>
                <p>
                    <label>End Time (seconds, 0 = till end):</label><br>
                    <input type="number" name="end_time" min="0" step="1" value="0">
                </p>
            </fieldset>

            <p style="margin-top:12px">
                <button type="submit" name="yt2pdf_submit" class="button button-primary">Generate PDF</button>
            </p>
        </form>
        <script>
            (function(){
                const modeRadios = document.querySelectorAll('input[name="mode"]');
                const scene = document.querySelector('input[name="scene"]').closest('div');
                const fps = document.querySelector('input[name="fps"]').closest('div');
                function sync(){
                    const mode = document.querySelector('input[name="mode"]:checked').value;
                    scene.style.opacity = (mode==='scene') ? 1 : 0.5;
                    fps.style.opacity   = (mode==='fps') ? 1 : 0.5;
                }
                modeRadios.forEach(r => r.addEventListener('change', sync));
                sync();
            })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function handle_form() {
        if (!isset($_POST['yt2pdf_submit']) || empty($_POST['yt_url'])) return;

	
    // Create a fresh working dir only when user submits
    $this->stamp   = date('Ymd_His');
    $this->workDir = rtrim($this->uploads['basedir'], "\\") . "\\" . 'ytp_' . $this->stamp;
    $this->workUrl = rtrim($this->uploads['baseurl'], '\\') . '/ytp_' . $this->stamp;

    if (!file_exists($this->workDir)) {
        wp_mkdir_p($this->workDir);
    }
    $this->framesDir = $this->workDir . "\\" . 'frames';
    if (!file_exists($this->framesDir)) {
        @mkdir($this->framesDir, 0777, true);
    }

        $url          = sanitize_text_field($_POST['yt_url']);
        $mode         = isset($_POST['mode']) ? (($_POST['mode'] === 'fps') ? 'fps' : 'scene') : 'scene';
        $fps_cap      = isset($_POST['fps']) ? max(0, (int)$_POST['fps']) : 0;
        $scene_thresh = isset($_POST['scene']) ? max(0.01, (float)$_POST['scene']) : 0.20;
        $max_pages    = isset($_POST['max_pages']) ? max(1, (int)$_POST['max_pages']) : 200;
        $min_width    = isset($_POST['width']) ? max(200, (int)$_POST['width']) : 800;
        $start_time   = isset($_POST['start_time']) ? max(0, (int)$_POST['start_time']) : 0;
        $end_time     = isset($_POST['end_time']) ? max(0, (int)$_POST['end_time']) : 0;

        // 1) Download video to $this->videoFile
        $dl_cmd = $this->q($this->yt_dlp)
                . ' -f mp4 -o ' . $this->q($this->videoFile)
                . ' ' . $this->q($url) . ' 2>&1';

        $dl_out = [];
        $dl_ret = 0;
        exec($dl_cmd, $dl_out, $dl_ret);

        if ($dl_ret !== 0 || !file_exists($this->videoFile)) {
            $msg = "Video download failed. Exit code: $dl_ret<br><strong>Command:</strong><pre>" . esc_html($dl_cmd) . "</pre>"
                 . "<strong>Output:</strong><pre>" . esc_html(implode("\n", $dl_out)) . "</pre>";
            wp_die($msg);
        }

        // 2) Build -vf (use either fixed FPS OR scene detection)
        if ($mode === 'fps' && $fps_cap > 0) {
            $vf = "fps={$fps_cap},scale={$min_width}:-1:flags=lanczos";
        } else {
            // quote the gt() arg to be robust on Windows cmd
            $vf = "select='gt(scene,{$scene_thresh})',scale={$min_width}:-1:flags=lanczos";
        }

        // 3) Time options (-ss/-t before -i for fast seeking). Only include -t if valid.
        $time_opts = '';
        if ($start_time > 0) {
            $time_opts .= ' -ss ' . (int)$start_time;
        }
        if ($end_time > 0 && $end_time > $start_time) {
            $duration  = (int)($end_time - $start_time);
            $time_opts .= ' -t ' . $duration;
        }

        // 4) Extract frames
		
        
		$frame_pattern = $this->framesDir . "\\" . 'frame_%04d.jpg';
		$this->reset_cache();
$ff_cmd = $this->q($this->ffmpeg)
        . $time_opts
        . ' -i ' . $this->q($this->videoFile)
        . ' -vf ' . $this->dq($vf)
        . ' -vsync vfr -y ' . $this->q($frame_pattern)
        . ' -hide_banner -loglevel error 2>&1';
		

        $ff_out = [];
        $ff_ret = 0;
        exec($ff_cmd, $ff_out, $ff_ret);

        // Save for debugging
        @file_put_contents($this->workDir .  "\\" . 'ffmpeg_cmd.txt', $ff_cmd . "\n\n" . implode("\n", $ff_out));

        // 5) Collect frames
        $images = glob($this->framesDir .  "\\" . "frame_*.jpg");
        if (!$images) $images = [];
        natsort($images);
        $images = array_values($images);

        if (empty($images)) {
            $hint = ($mode === 'scene')
                ? "Try lowering the scene threshold (e.g., 0.10 or 0.05)."
                : "Try increasing FPS.";
            $msg = "FFmpeg failed to extract frames.<br><strong>Command:</strong><br><code>" . esc_html($ff_cmd) . "</code><br><br>"
                 . "<strong>Output:</strong><pre>" . esc_html(implode("\n", $ff_out)) . "</pre><br>"
                 . $hint;
            wp_die($msg);
        }

        // 6) Cap max pages
        if ($max_pages > 0 && count($images) > $max_pages) {
            $images = array_slice($images, 0, $max_pages);
        }

        // 7) Build PDF
        if (!class_exists('TCPDF')) {
            require_once __DIR__ . "\\" . 'tcpdf' . "\\" . 'tcpdf.php';
        }

        $pdf = new TCPDF();
        $pdf->SetCreator('YouTubeToPDF');
        $pdf->SetAuthor('YT2PDF Plugin');
        $pdf->SetTitle('YouTube to PDF');
        $pdf->SetMargins(10, 10, 10);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        foreach ($images as $img) {
            $pdf->AddPage();
            // Fit width ~180mm (leave 15mm margins)
            $pdf->Image($img, 15, 15, 180, 0, 'JPG');
        }

        $pdf_file = $this->workDir . "\\" . 'output.pdf';
        $pdf->Output($pdf_file, 'F');

        // 8) Done → link
        $pdf_url = $this->workUrl . '\output.pdf';
        wp_die("✅ PDF generated successfully!<br><a href='" . esc_url($pdf_url) . "' target='_blank' rel='noopener'>Download PDF</a>");
    }
}

new YouTubeToPDF();

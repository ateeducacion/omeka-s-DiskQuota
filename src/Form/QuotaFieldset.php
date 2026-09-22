<?php
declare(strict_types=1);

namespace DiskQuota\Form;

class QuotaFieldset
{
    /** Append the existing usage partial below the editable or read-only quota field. */
    public static function appendUsage(string $html, string $targetId, bool $userStyles = false): void
    {
        $escapedHtml = str_replace(["\n", "\r", "'", '"'], ['', '', "\\'", '\\"'], $html);
        $style = $userStyles ? '
            var style = document.createElement("style");
            style.textContent = ".show .property, .meta-group {"
                + "display: block !important; justify-content: left !important; }";
            document.head.appendChild(style);
        ' : '';
        echo '
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var quotaField = document.getElementById("' . $targetId . '");
                if (quotaField) {
                    var fieldContainer = quotaField.closest(".field");
                    if (fieldContainer) {
                        var inputsDiv = fieldContainer.querySelector(".inputs");
                        if (inputsDiv) {
                            var htmlContent = \'' . $escapedHtml . '\';
                            inputsDiv.insertAdjacentHTML("beforeend", htmlContent);
                            ' . $style . '
                        }
                    }
                }
            });
        </script>';
    }
}

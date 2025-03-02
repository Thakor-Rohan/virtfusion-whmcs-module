<?php

use WHMCS\Module\Server\VirtFusionDirect\ConfigureService;
use WHMCS\User\User;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (!isset($vars['productinfo']['module']) || $vars['productinfo']['module'] !== 'VirtFusionDirect') {
        return null;
    }

    $cs = new ConfigureService();

    $templates_data = $cs->fetchTemplates(
        $cs->fetchPackageByDbId($vars['productinfo']['pid']) ?? $cs->fetchPackageId($vars['productinfo']['name'])
    );

    if (empty($templates_data)) {
        return null;
    }

    // Group OS templates by normalized OS family (adjust normalization as needed)
    $osGroups = [];
    foreach ($templates_data['data'] as $osCategory) {
        foreach ($osCategory['templates'] as $template) {
            $originalOsFamily = $template['name'];
            // Normalize "Ubuntu" variants into one group (add similar rules for others if required)
            if (stripos($originalOsFamily, 'ubuntu') !== false) {
                $normalizedOsFamily = 'Ubuntu';
            } else {
                $normalizedOsFamily = $originalOsFamily;
            }
            $version = $template['version'];
            $variant = $template['variant'];
            if (!isset($osGroups[$normalizedOsFamily])) {
                $osGroups[$normalizedOsFamily] = [
                    'versions' => []
                ];
            }
            $osGroups[$normalizedOsFamily]['versions'][] = [
                'id' => $template['id'],
                'label' => trim($version . ' ' . $variant)
            ];
        }
    }
    ksort($osGroups);

    // Process SSH keys
    $sshKeys = $cs->getUserSshKeys($vars['loggedinuser']);
    $sshKeysOptions = array_values(array_filter(array_map(function ($sshKey) {
        if ($sshKey['enabled'] === false) {
            return null;
        }
        return [
            'id' => $sshKey['id'],
            'name' => $sshKey['name']
        ];
    }, $sshKeys['data'] ?? [])));

    // Get custom field IDs for OS and SSH
    $osID = array_values(array_filter(array_map(function ($option) {
        if ($option['textid'] === 'initialoperatingsystem') {
            return $option['id'];
        }
    }, $vars['customfields'])));
    $sshID = array_values(array_filter(array_map(function ($option) {
        if ($option['textid'] === 'initialsshkey') {
            return $option['id'];
        }
    }, $vars['customfields'])));

    return "
    <style>
        /* Overall OS selection container */
        .os-selection-container {
            margin-bottom: 20px;
        }
        /* OS family cards styled like DigitalOcean’s selection */
        .os-group-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        .os-group-card {
            width: 120px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
            background-color: #fff;
        }
        .os-group-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .os-group-card.active {
            border-color: #007bff;
            box-shadow: 0 4px 8px rgba(0,123,255,0.2);
        }
        .os-group-card img {
            max-width: 100%;
            height: 40px;
            margin-bottom: 8px;
        }
        /* External version dropdown styling */
        .version-dropdown-container {
            margin-top: 10px;
            max-width: 300px;
        }
        .version-dropdown {
            width: 100%;
        }
        /* SSH key card styling */
        .ssh-key-container {
            margin-top: 20px;
        }
        .ssh-key-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            background-color: #fff;
            margin-bottom: 10px;
        }
        .ssh-key-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .ssh-key-card.active {
            border-color: #28a745;
            box-shadow: 0 4px 8px rgba(40,167,69,0.2);
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // OS groups: key is normalized OS family, value contains an array of versions.
        let osGroups = " . json_encode($osGroups, JSON_THROW_ON_ERROR) . ";
        let sshKeys = " . json_encode($sshKeysOptions, JSON_THROW_ON_ERROR) . ";

        const osInputField = document.querySelector('[name=\"customfield[" . ($osID[0] ?? null) . "]\"]');
        const sshInputField = document.querySelector('[name=\"customfield[" . ($sshID[0] ?? null) . "]\"]');
        const sshInputLabel = document.querySelector('[for=\"customfield" . ($sshID[0] ?? null) . "\"]');

        // Dynamic OS icon mapping based on OS family name.
        function getOSIconUrl(osFamily) {
            var osLower = osFamily.toLowerCase();
            if (osLower.indexOf('windows') !== -1) {
                return 'https://servers.flashrdp.com/img/logo/windows_logo.png';
            } else if (osLower.indexOf('ubuntu') !== -1) {
                return 'https://servers.flashrdp.com/img/logo/ubuntu_logo.png';
            } else if (osLower.indexOf('almalinux') !== -1) {
                return 'https://servers.flashrdp.com/img/logo/almalinux_logo.png';
            } else if (osLower.indexOf('centos') !== -1) {
                return 'https://servers.flashrdp.com/img/logo/centos_logo.png';
            } else if (osLower.indexOf('debian') !== -1) {
                return 'https://servers.flashrdp.com/img/logo/debian_logo.png';
            } else if (osLower.indexOf('fedora') !== -1) {
                return 'https://servers.flashrdp.com/img/logo/fedora_logo.png';
            } else {
                // Fallback for other Linux distributions
                return 'https://servers.flashrdp.com/img/logo/linux_logo.png';
            }
        }

        // Create container for OS selection
        let osSelectionContainer = document.createElement('div');
        osSelectionContainer.className = 'os-selection-container';

        // Create container for OS family cards
        let osGroupContainer = document.createElement('div');
        osGroupContainer.className = 'os-group-container';

        // Create external version dropdown container and the dropdown element
        let versionDropdownContainer = document.createElement('div');
        versionDropdownContainer.className = 'version-dropdown-container';
        let versionDropdown = document.createElement('select');
        versionDropdown.className = 'form-control version-dropdown';
        versionDropdownContainer.appendChild(versionDropdown);

        // Function to populate the version dropdown for the selected OS family
        function populateVersionDropdown(versions) {
            versionDropdown.innerHTML = '';
            versions.forEach(function(version) {
                let option = document.createElement('option');
                option.value = version.id;
                option.text = version.label;
                versionDropdown.appendChild(option);
            });
            if (versionDropdown.options.length > 0) {
                osInputField.value = versionDropdown.options[0].value;
            }
        }

        // Prepare SSH key container variable in the outer scope.
        let sshKeyContainer = null;
        if (sshKeys.length > 0) {
            sshKeyContainer = document.createElement('div');
            sshKeyContainer.className = 'ssh-key-container';
            
            sshKeys.forEach(function(sshkey) {
                let card = document.createElement('div');
                card.className = 'ssh-key-card';
                card.textContent = sshkey.name;
                card.dataset.value = sshkey.id;
                card.addEventListener('click', function() {
                    sshKeyContainer.querySelectorAll('.ssh-key-card.active').forEach(function(item) {
                        item.classList.remove('active');
                    });
                    card.classList.add('active');
                    sshInputField.value = sshkey.id;
                });
                sshKeyContainer.appendChild(card);
            });
        
            if (sshKeyContainer.firstChild) {
                sshKeyContainer.firstChild.classList.add('active');
                sshInputField.value = sshKeyContainer.firstChild.dataset.value;
            }
        
            sshInputField.parentNode.insertBefore(sshKeyContainer, sshInputField.nextSibling);
            sshInputField.style.display = 'none';
        } else {
            sshInputField.style.display = 'none';
            if (sshInputLabel) {
                sshInputLabel.style.display = 'none';
            }
        }

        // Build OS family cards with dynamic icons.
        let firstGroupSelected = false;
        for (const osFamily in osGroups) {
            let card = document.createElement('div');
            card.className = 'os-group-card';

            // Create image element for OS icon
            let img = document.createElement('img');
            img.src = getOSIconUrl(osFamily);
            img.alt = osFamily;
            card.appendChild(img);

            // Add OS family label below the icon
            let label = document.createElement('div');
            label.textContent = osFamily;
            card.appendChild(label);

            card.dataset.osFamily = osFamily;
            card.addEventListener('click', function() {
                // Remove active state from all OS cards
                osGroupContainer.querySelectorAll('.os-group-card.active').forEach(function(item) {
                    item.classList.remove('active');
                });
                card.classList.add('active');
                populateVersionDropdown(osGroups[osFamily].versions);

                // If Windows is selected, hide SSH key container and clear its value.
                if (card.dataset.osFamily.toLowerCase().indexOf('windows') !== -1) {
                    if (sshKeyContainer) {
                        sshKeyContainer.style.display = 'none';
                    }
                    sshInputField.value = '';
                } else {
                    if (sshKeyContainer) {
                        sshKeyContainer.style.display = 'block';
                    }
                }
            });
            osGroupContainer.appendChild(card);
            // Set the first OS family card as active by default.
            if (!firstGroupSelected) {
                card.classList.add('active');
                populateVersionDropdown(osGroups[osFamily].versions);
                if (card.dataset.osFamily.toLowerCase().indexOf('windows') !== -1) {
                    if (sshKeyContainer) {
                        sshKeyContainer.style.display = 'none';
                    }
                    sshInputField.value = '';
                } else {
                    if (sshKeyContainer) {
                        sshKeyContainer.style.display = 'block';
                    }
                }
                firstGroupSelected = true;
            }
        }

        osSelectionContainer.appendChild(osGroupContainer);
        // Append the external version dropdown container below the OS group container.
        osSelectionContainer.appendChild(versionDropdownContainer);

        // Insert OS selection container after the hidden OS input field
        osInputField.parentNode.insertBefore(osSelectionContainer, osInputField.nextSibling);
        osInputField.style.display = 'none';
    });
    </script>
    ";
});

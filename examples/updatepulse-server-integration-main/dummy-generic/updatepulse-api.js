// ### EXAMPLE INTEGRATION WITH UPDATEPULSE SERVER ###

// DO NOT USE THIS FILE AS IT IS IN PRODUCTION !!!
// It is just a collection of basic functions and snippets, and they do not
// perform the necessary checks to ensure data integrity; they assume that all
// the requests are successful, and do not check paths or permissions.
// They also assume that the package necessitates a license key.

// replace https://server.domain.tld/ with the URL of the server where
// UpdatePulse Server is installed in updatepulse.json

const modules = require('./node-dist/exports.js');
const https = modules.https;
const fs = modules.fs;
const querystring = require('querystring');
const events = require('events');
const path = require('path');
const os = require('os');
const updatepulseApi = new events.EventEmitter();
const AdmZip = modules.AdmZip;
const machineIdSync = modules.machineIdSync;

// define the package name
let package_name = path.basename(__dirname)

function compareVersions(v1, v2) {
    let v1parts = v1.split('.').map(Number);
    let v2parts = v2.split('.').map(Number);

    for (let i = 0; i < v1parts.length; ++i) {

        if (v2parts.length === i) {
            return 1;
        }

        if (v1parts[i] === v2parts[i]) {
            continue;
        }

        if (v1parts[i] > v2parts[i]) {
            return 1;
        }

        return -1;
    }

    if (v1parts.length !== v2parts.length) {
        return -1;
    }

    return 0;
}

function chmodRecursive(dir, dirMod = '755', fileMod = '644') {
    let files = fs.readdirSync(dir);

    for (let i = 0; i < files.length; i++) {
        let file = files[i];
        let filePath = path.join(dir, file);

        if (file.startsWith(package_name)) {
            fs.chmodSync(filePath, dirMod);
        } else if (fs.lstatSync(filePath).isDirectory()) {
            fs.chmodSync(filePath, dirMod);
            chmodRecursive(filePath);
        } else {
            fs.chmodSync(filePath, fileMod);
        }
    }
}

async function main() {
    let config = require('./updatepulse.json');
    // define the url of the server
    let url = config.server.replace(/\/+$/, '');
    // define the package script
    let package_script = __filename;
    // define the current script name
    let script_name = path.basename(__filename);
    // define the current version of the package from the updatepulse.json file
    let version = config.packageData.Version;
    // define license_key from the updatepulse.json file
    let license_key = config.licenseKey ? config.licenseKey : '';
    // define license_signature from the updatepulse.json file
    let license_signature = config.licenseSignature ? config.licenseSignature : '';
    // define the domain
    let domain = "";

    if ("Darwin" === os.type()) {
        domain = machineIdSync().replace(/\n+$/, "");;
    } else if ("Linux" === os.type()) {
        domain = fs.readFileSync('/var/lib/dbus/machine-id', 'utf8').replace(/\n+$/, "");;
    }

    // ### INSTALLING THE PACKAGE ###

    const install = async function (license_key) {
        // add the license key to updatepulse.json
        config.licenseKey = license_key;
        // add a file '.installed' in current directory
        fs.writeFileSync(path.join(__dirname, '.installed'), '');
        // write the new updatepulse.json file
        fs.writeFileSync(path.join(__dirname, 'updatepulse.json'), JSON.stringify(config, null, 4));
    };

    // ### UNINSTALLING THE PACKAGE ###

    const uninstall = async function () {
        // remove the license key from updatepulse.json
        delete config.licenseKey;
        // remove the license signature from updatepulse.json
        delete config.licenseSignature;
        // remove the file '.installed' from current directory
        if (parseInt(process.version.slice(1).split('.')[0]) >= 14) {
            fs.rmSync(path.join(__dirname, '.installed'));
        } else {
            fs.unlinkSync(path.join(__dirname, '.installed'));
        }

        // write the new updatepulse.json file
        fs.writeFileSync(path.join(__dirname, 'updatepulse.json'), JSON.stringify(config, null, 4));

        license_signature = "";
    };

    // ### CHECKING IF THE PACKAGE IS INSTALLED ###

    const is_installed = function () {
        // check if the file '.installed exists in current directory
        return fs.existsSync(path.join(__dirname, '.installed'));
    };

    // ### SENDING AN API REQUEST ###

    const send_api_request = function (endpoint, args) {
        // build the request url
        let full_url = url.replace(/\/$/, "") + '/' + endpoint + '/?' + querystring.stringify(args);
        // make the request
        return new Promise((resolve, reject) => {
            let response = '';

            https.get(full_url, (res) => {
                res.on('data', (d) => {
                    response += d;
                });
                res.on('end', () => {
                    resolve(response);
                });
            }).on('error', (e) => {
                console.error(e);
                reject(e);
            });
        });
    };

    // ### CHECKING FOR UPDATES ###

    const check_for_updates = async function () {
        // build the request url
        let endpoint = "updatepulse-server-update-api";
        let args = {
            action: "get_metadata",
            package_id: package_name,
            installed_version: version,
            license_key: license_key,
            license_signature: license_signature,
            update_type: "Generic"
        };
        // make the request
        let response = await send_api_request(endpoint, args);

        // return the response
        return response;
    };

    // ### ACTIVATING A LICENSE ###

    const activate_license = async function () {
        // build the request url
        let endpoint = "updatepulse-server-license-api";
        let args = {
            action: "activate",
            license_key: license_key,
            allowed_domains: domain,
            package_slug: package_name
        };
        // make the request
        let response = await send_api_request(endpoint, args);
        // get the signature from the response
        let signature = JSON.parse(decodeURIComponent(response)).license_signature;

        // add the license signature to updatepulse.json
        config.licenseSignature = signature;
        // write the new updatepulse.json file
        fs.writeFileSync(path.join(__dirname, 'updatepulse.json'), JSON.stringify(config, null, 4));

        license_signature = signature;
    };

    // ### DEACTIVATING A LICENSE ###

    const deactivate_license = function () {
        // build the request url
        let endpoint = "updatepulse-server-license-api";
        let args = {
            action: "deactivate",
            license_key: license_key,
            allowed_domains: domain,
            package_slug: package_name
        };
        // make the request
        send_api_request(endpoint, args);
        // remove the license signature from updatepulse.json
        delete config.licenseSignature;
        // write the new updatepulse.json file
        fs.writeFileSync(path.join(__dirname, 'updatepulse.json'), JSON.stringify(config, null, 4));

        license_signature = "";
    };

    // ### DOWNLOADING THE PACKAGE ###

    const download_update = function (response) {
        // get the download url from the response
        let url = JSON.parse(response).download_url;
        // set the path to the downloaded file
        let output_file = path.join(os.tmpdir(), package_name + '.zip');
        // make the request
        return new Promise((resolve, reject) => {
            let file = fs.createWriteStream(output_file);

            https.get(url, (res) => {
                res.pipe(file);
                file.on('finish', () => {
                    file.close(() => {
                        resolve(output_file);
                    });
                });
            }).on('error', (e) => {
                console.error(e);
                reject(e);
            });
        });
    };

    // ### GETTING THE PACKAGE VERSION ###

    const get_version = function () {
        // return the current version of the package
        return version;
    };

    // ### UPDATING THE PACKAGE ###

    const update = async function () {
        // check for updates
        let response = await check_for_updates();
        // get the version from the response
        let new_version = JSON.parse(response).version;

        if (compareVersions(version, new_version) < 0) {
            // download the update
            let output_file = await download_update(response);

            // extract the zip in /tmp/$(package_name)
            let zip = new AdmZip(output_file);

            if (fs.existsSync('/tmp/' + package_name)) {
                fs.rmSync('/tmp/' + package_name, { recursive: true, force: true });
            }

            zip.extractAllTo('/tmp/');

            if (fs.existsSync('/tmp/' + package_name)) {
                // get the permissions of the current script
                let octal_mode = fs.statSync(package_script).mode.toString(8).slice(-4);

                // set the permissions of the new main scripts to the permissions of the
                // current script
                let files = fs.readdirSync('/tmp/' + package_name);

                for (let i = 0; i < files.length; i++) {
                    let file = files[i];

                    // check if the file starts with the package name
                    if (file.startsWith(package_name)) {
                        fs.chmodSync('/tmp/' + package_name + '/' + file, octal_mode);
                    }
                }

                // delete all files in the current directory, except for update scripts
                files = fs.readdirSync(__dirname);

                for (let i = 0; i < files.length; i++) {
                    let file = files[i];

                    if (".installed" === file) {
                        continue;
                    }

                    // check if the file does not start with `updatepulse`, or is .json
                    if (!file.startsWith("updatepulse") || file.endsWith(".json")) {
                        let deletePath = path.join(__dirname, file);

                        if (fs.existsSync(deletePath)) {
                            fs.rmSync(deletePath, { recursive: true, force: true });
                        }
                    }
                }

                // move the updated package files to the current directory; the
                // updated package is in charge of overriding the update scripts
                // with new ones after update (may be contained in a subdirectory)
                files = fs.readdirSync('/tmp/' + package_name);

                for (let i = 0; i < files.length; i++) {
                    let file = files[i];

                    // check if the file does not start with `updatepulse`, or is .json
                    if (!file.startsWith("updatepulse") || file.endsWith(".json")) {
                        fs.moveSync('/tmp/' + package_name + '/' + file, path.join(__dirname, file));
                    }
                }

                // recursively set all files to 644 and all directories to 755
                chmodRecursive(__dirname);
                // remove the directory
                fs.rmSync('/tmp/' + package_name, { recursive: true, force: true });
            }

            config = JSON.parse(fs.readFileSync(path.join(__dirname, 'updatepulse.json'), 'utf8'));

            // add the license key to updatepulse.json
            config.licenseKey = license_key;
            // add the license signature to updatepulse.json
            config.licenseSignature = license_signature;
            // write the new updatepulse.json file
            fs.writeFileSync(path.join(__dirname, 'updatepulse.json'), JSON.stringify(config, null, 4));

            // remove the zip
            fs.unlinkSync(output_file);
        }
    };

    // ### GETTING THE PACKAGE INFO ###

    const get_update_info = async function () {
        // get the update information
        return JSON.parse(decodeURIComponent(await check_for_updates()));
    };

    return {
        install: install,
        uninstall: uninstall,
        is_installed: is_installed,
        check_for_updates: check_for_updates,
        activate_license: activate_license,
        deactivate_license: deactivate_license,
        get_version: get_version,
        update: update,
        get_update_info: get_update_info
    };
}

main().then(function(result) {
    updatepulseApi.emit('ready', result);
});

module.exports = updatepulseApi; // export the event variable
# pylint: disable=C0103
"""
### EXAMPLE INTEGRATION WITH UPDATEPULSE SERVER ###

DO NOT USE THIS FILE AS IT IS IN PRODUCTION !!!
It is just a collection of basic functions and snippets, and they do not
perform the necessary checks to ensure data integrity; they assume that all
the requests are successful, and do not check paths or permissions.
They also assume that the package necessitates a license key.

replace https://server.domain.tld/ with the URL of the server where
UpdatePulse Server is installed in updatepulse.json
"""

import os
import sys
import json
import subprocess
import urllib.parse
import urllib.request
import urllib.error
import zipfile
import shutil
import tempfile
import requests

conf = "updatepulse.json"

# get the config from updatepulse.json in a variable
with open(os.path.join(os.path.dirname(__file__), conf), encoding="utf-8") as config_file:
    updatepulse_config = json.load(config_file)

# define the url of the server
url = updatepulse_config["server"]
# define the package name
package_name = os.path.basename(os.path.dirname(__file__))
# define the package script
package_script = os.path.join(os.path.dirname(__file__), os.path.basename(__file__))
# define the current script name
script_name = os.path.basename(__file__)
# define the current version of the package from the updatepulse.json file
version = updatepulse_config["packageData"]["Version"]

# define license_key from the updatepulse.json file - check if it exists to avoid errors
if "licenseKey" in updatepulse_config:
    license_key = updatepulse_config["licenseKey"]
else:
    license_key = ""

# define license_signature from the updatepulse.json file - check if it exists to avoid errors
if "licenseSignature" in updatepulse_config:
    license_signature = updatepulse_config["licenseSignature"]
else:
    license_signature = ""

# define the domain
if sys.platform == "darwin":
    # macOS
    command = ["ioreg", "-rd1", "-c", "IOPlatformExpertDevice"]
    domain = subprocess.check_output(command).decode("utf-8")
elif sys.platform == "linux":
    # Ubuntu
    domain = subprocess.check_output(["cat", "/var/lib/dbus/machine-id"]).decode("utf-8").strip()


def install(_license_key):
    """
    ### INSTALLING THE PACKAGE ###
    """
    global license_key # pylint: disable=global-statement
    # add the license key to updatepulse.json
    license_key = _license_key
    updatepulse_config["licenseKey"] = license_key

    # add a file '.installed' in current directory
    with open(os.path.join(os.path.dirname(__file__), ".installed"), "w", encoding="utf-8") as _:
        pass

    with open(os.path.join(os.path.dirname(__file__), conf), "w", encoding="utf-8") as f:
        json.dump(updatepulse_config, f, indent=4)

def uninstall():
    """
    ### UNINSTALLING THE PACKAGE ###
    """
    # remove the license key from updatepulse.json
    updatepulse_config.pop("licenseKey", None)
    # remove the license signature from updatepulse.json
    updatepulse_config.pop("licenseSignature", None)

    # remove the file '.installed' from current directory
    os.remove(os.path.join(os.path.dirname(__file__), ".installed"))

    with open(os.path.join(os.path.dirname(__file__), conf), "w", encoding="utf-8") as f:
        json.dump(updatepulse_config, f, indent=4)


def is_installed():
    """
    ### CHECKING IF THE PACKAGE IS INSTALLED ###
    """
    # check if the file '.installed exists in current directory
    if os.path.isfile(os.path.join(os.path.dirname(__file__), ".installed")):

        # return true
        return True

    # return false
    return False


def _send_api_request(endpoint, args):
    """
    ### SENDING AN API REQUEST ###
    """
    # build the request url
    full_url = url.rstrip('/') + "/" + endpoint + "/?" + "&".join(args)
    # set headers
    headers = {
        "user-agent": "curl",
        "accept": "*/*"
    }
    # make the request
    response = requests.get(full_url, headers=headers, timeout=20, verify=True)

    # return the response
    return response.text

def _check_for_updates():
    """
    ### CHECKING FOR UPDATES ###
    """
    # build the request url
    endpoint = "updatepulse-server-update-api"
    args = [
        "action=get_metadata",
        "package_id=" + urllib.parse.quote(package_name),
        "installed_version=" + urllib.parse.quote(version),
        "license_key=" + urllib.parse.quote(license_key),
        "license_signature=" + urllib.parse.quote(license_signature),
        "update_type=Generic"
    ]
    # make the request
    response = _send_api_request(endpoint, args)

    # return the response
    return response


def activate_license():
    """
    ### ACTIVATING A LICENSE ###
    """
    global license_signature # pylint: disable=global-statement
    # build the request url
    endpoint = "updatepulse-server-license-api"
    args = [
        "action=activate",
        "license_key=" + urllib.parse.quote(license_key),
        "allowed_domains=" + urllib.parse.quote(domain),
        "package_slug=" + urllib.parse.quote(package_name)
    ]
    # make the request
    response = _send_api_request(endpoint, args)
    # get the signature from the response
    signature = json.loads(response)["license_signature"]
    # add the license signature to updatepulse.json
    license_signature = signature
    updatepulse_config["licenseSignature"] = license_signature

    with open(os.path.join(os.path.dirname(__file__), conf), "w", encoding="utf-8") as f:
        json.dump(updatepulse_config, f, indent=4)

def deactivate_license():
    """
    ### DEACTIVATING A LICENSE ###
    """
    # build the request url
    endpoint = "updatepulse-server-license-api"
    args = [
        "action=deactivate",
        "license_key=" + urllib.parse.quote(license_key),
        "allowed_domains=" + urllib.parse.quote(domain),
        "package_slug=" + urllib.parse.quote(package_name)
    ]
    # make the request
    _send_api_request(endpoint, args)
    # remove the license signature from updatepulse.json
    updatepulse_config.pop("licenseSignature", None)

    with open(os.path.join(os.path.dirname(__file__), conf), "w", encoding="utf-8") as f:
        json.dump(updatepulse_config, f, indent=4)

def _download_update(response):
    """
    ### DOWNLOADING THE PACKAGE ###
    """
    # get the download url from the response
    _url = json.loads(response)["download_url"]
    # set the path to the downloaded file
    output_file = os.path.join(tempfile.gettempdir(), package_name + ".zip")

    # make the request
    headers = {
        'User-Agent': 'curl'
    }
    request = urllib.request.Request(_url, headers=headers)

    with urllib.request.urlopen(request) as _response:

        with open(output_file, 'wb') as out_file:
            out_file.write(_response.read())

    # return the path to the downloaded file
    return output_file

def get_version():
    """
    ### GETTING THE PACKAGE VERSION ###
    """
    # return the current version of the package
    return version

def update():
    """
    ### UPDATING THE PACKAGE ###
    """
    # check for updates
    response = _check_for_updates()
    # get the version from the response
    new_version = json.loads(response)["version"]

    if new_version > version:
        # download the update
        output_file = _download_update(response)

        # extract the zip in /tmp/$(package_name)
        with zipfile.ZipFile(output_file, "r") as zip_file:
            # delete the zip if it exists
            if os.path.exists(os.path.join(tempfile.gettempdir(), package_name)):
                shutil.rmtree(os.path.join(tempfile.gettempdir(), package_name))
            zip_file.extractall(tempfile.gettempdir())

        if os.path.isdir(os.path.join(tempfile.gettempdir(), package_name)):
            global updatepulse_config # pylint: disable=global-statement
            global config_file # pylint: disable=global-statement

            conf_path = os.path.join(os.path.dirname(__file__), conf)

            # delete all files in the current directory, except for update scripts
            for file in os.listdir(os.path.dirname(__file__)):

                if file == ".installed":
                    continue

                # check if the file does not start with `updatepulse`, or is .json
                if not file.startswith("updatepulse") or file.endswith(".json"):

                    if os.path.isfile(os.path.join(os.path.dirname(__file__), file)):
                        os.remove(os.path.join(os.path.dirname(__file__), file))
                    elif os.path.isdir(os.path.join(os.path.dirname(__file__), file)):
                        shutil.rmtree(os.path.join(os.path.dirname(__file__), file))

            # move the updated package files to the current directory; the
            # updated package is in charge of overriding the update scripts
            # with new ones after update (may be contained in a subdirectory)
            for file in os.listdir(os.path.join(tempfile.gettempdir(), package_name)):

                # check if the file does not start with `updatepulse`, or is .json
                if not file.startswith("updatepulse") or file.endswith(".json"):
                    src = os.path.join(tempfile.gettempdir(), package_name, file)
                    dest = os.path.join(os.path.dirname(__file__), file)

                    if os.path.exists(dest):
                        os.remove(dest)

                    shutil.move(src, dest)

            # recursively set all files to 644 and all directories to 755
            for dirpath, dirnames, filenames in os.walk(os.path.dirname(__file__)):

                for file in filenames:
                    filepath = os.path.join(dirpath, file)

                    if os.path.isfile(filepath) and file.startswith(package_name):
                        os.chmod(filepath, 0o755)
                    elif os.path.isfile(filepath):
                        os.chmod(filepath, 0o644)

                for _dir in dirnames:
                    dirpath = os.path.join(dirpath, _dir)

                    if os.path.isdir(dirpath):
                        os.chmod(dirpath, 0o755)

            with open(conf_path, encoding="utf-8") as config_file:
                updatepulse_config = json.load(config_file)

            # add the license key to updatepulse.json
            updatepulse_config["licenseKey"] = license_key
            # add the license signature to updatepulse.json
            updatepulse_config["licenseSignature"] = license_signature

            with open(conf_path, "w", encoding="utf-8") as f:
                json.dump(updatepulse_config, f, indent=4)

            # remove the directory
            shutil.rmtree(os.path.join(tempfile.gettempdir(), package_name))

        # remove the zip
        os.remove(output_file)

def get_update_info():
    """
    ### GETTING THE PACKAGE INFO ###
    """
    response = _check_for_updates()
    # get the update information
    return json.loads(urllib.parse.unquote(response))

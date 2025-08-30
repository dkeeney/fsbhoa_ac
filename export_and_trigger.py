# === HOA Export and Trigger Script ===
#
# Description:
# This script automates the process of logging into the Vantaca property management
# website, exporting the required resident data as an Excel file, converting it to
# CSV, saving it to the NAS, and triggering the secure import API on the server.
#
# --- Prerequisites ---
# 1. Python: Installed from python.org. During installation, ensure the
#    "Add Python to PATH" checkbox is selected.
# 2. Required Libraries: Install by opening a Command Prompt and running:
#    pip install selenium requests pandas openpyxl
# 3. Chrome WebDriver: The chromedriver.exe that matches the installed version
#    of the Chrome browser must be placed in C:\WebDriver\
#
# --- How to Use ---
# 1. Fill in all the values in the CONFIGURATION section below.
# 2. Use the TestCase Studio browser extension to find the correct CSS selectors.
# 3. To run a live import that modifies the database, change the "RUN_MODE"
#    variable to "live".

import time
import shutil
import os
import requests
import json
import pandas as pd
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

# --- 1. CONFIGURATION ---
RUN_MODE = "dry_run" # Change to "live" for a real import

# NEW: Read credentials from the config.ini file
config = configparser.ConfigParser()
config.read('config.ini')

LOGIN_URL = "https://e9.vantaca.net/Home/Login?ReturnUrl=%2f"
HOMEOWNER_LIST_URL = "https://e9.vantaca.net/Reports/HomeownerList" 
USERNAME = config['vantaca']['username'] # Read from config
PASSWORD = config['vantaca']['password'] # Read from config


# --- Selectors ---
COLUMNS_BUTTON_SELECTOR = "button[data-bs-original-title='Columns']"
ALL_COLUMNS_SELECTOR = "ul[aria-labelledby='dropdownMenuButton1'] input[type='checkbox']"
COLUMNS_TO_SELECT = [
    "input[data-field='propAddressNoLBL']", 
    "input[data-field='firstName']", 
    "input[data-field='lastName']",
    "input[data-field='spouseFirstName']", 
    "input[data-field='spouseLastName']", 
    "input[data-field='phone']",
    "input[data-field='eMail']", 
    "input[data-field='tenantName']", 
    "input[data-field='tenantEmails']",
    "input[data-field='tenantPhones']"
]
EXPORT_BUTTON_XPATH = "//button[normalize-space()='Export to Excel']"

# --- File Paths & API ---
CHROME_DRIVER_PATH = "C:\\WebDriver\\chromedriver.exe"
DOWNLOAD_DIR = f"C:\\Users\\{os.getlogin()}\\Downloads"
DOWNLOADED_FILENAME_PATTERN = "Homeowner Export"
NAS_PATH_CSV = "\\\\nas.fsbhoa.com\\shared\\AccessControl\\ResidentImport.csv"
API_URL = "https://access.fsbhoa.com/wp-json/fsbhoa/v1/import/run"
API_KEY = "ArZ5Zvhdq6d6Xtp4eHjHh2iua+/0M8UstTu3FrO9rNY="
SERVER_FILE_PATH = "/mnt/shared/AccessControl/ResidentImport.csv"

# --- 2. SCRIPT LOGIC ---
print(f"--- Starting HOA Export and Import Trigger ---")
print(f"*** MODE: {RUN_MODE.upper()} ***")
if RUN_MODE != "live":
    print("*** No changes will be made to the database. ***")

service = webdriver.ChromeService(executable_path=CHROME_DRIVER_PATH)
options = webdriver.ChromeOptions()
prefs = {"download.default_directory": DOWNLOAD_DIR, "safebrowsing.enabled": True}
options.add_experimental_option("prefs", prefs)
driver = webdriver.Chrome(service=service, options=options)

try:
    print("\n>>> Step 1: Logging into Vantaca...")
    driver.get(LOGIN_URL)
    WebDriverWait(driver, 10).until(EC.visibility_of_element_located((By.CSS_SELECTOR, "#login"))).send_keys(USERNAME)
    driver.find_element(By.CSS_SELECTOR, "#password").send_keys(PASSWORD)
    driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
    print("   Login submitted successfully.")

    print("\n>>> Step 2: Configuring the Homeowner List report...")
    WebDriverWait(driver, 20).until(EC.element_to_be_clickable((By.XPATH, "//a[normalize-space()='Homeowner List']"))).click()
    WebDriverWait(driver, 20).until(EC.visibility_of_element_located((By.ID, "HOListGrid")))
    WebDriverWait(driver, 10).until(EC.element_to_be_clickable((By.CSS_SELECTOR, COLUMNS_BUTTON_SELECTOR))).click()
    print("   Columns menu opened.")
    time.sleep(1)

    print("   First Pass: Deselecting all visible columns...")
    all_checkboxes = driver.find_elements(By.CSS_SELECTOR, ALL_COLUMNS_SELECTOR)
    for checkbox in all_checkboxes:
        if checkbox.is_selected():
            checkbox.click()
            time.sleep(0.1)

    print("\n   Second Pass: Selecting required columns...")
    for selector in COLUMNS_TO_SELECT:
        try:
            checkbox = driver.find_element(By.CSS_SELECTOR, selector)
            if not checkbox.is_selected():
                checkbox.click()
                print(f"      - Selected: {selector}")
                time.sleep(0.1)
        except Exception:
            print(f"      - WARNING: Could not find required column {selector}!")
    
    print("\n>>> Step 3: Exporting data...")
    for item in os.listdir(DOWNLOAD_DIR):
        if item.startswith(DOWNLOADED_FILENAME_PATTERN) and item.endswith(".xlsx"): os.remove(os.path.join(DOWNLOAD_DIR, item))
    WebDriverWait(driver, 10).until(EC.element_to_be_clickable((By.XPATH, EXPORT_BUTTON_XPATH))).click()
    print("   Export command clicked. Waiting for download...")
    
    downloaded_file_path = ""
    timeout = 60
    while timeout > 0:
        found = False
        for item in os.listdir(DOWNLOAD_DIR):
            if item.startswith(DOWNLOADED_FILENAME_PATTERN) and item.endswith(".xlsx"):
                downloaded_file_path = os.path.join(DOWNLOAD_DIR, item)
                found = True
                break
        if found: break
        time.sleep(1)
        timeout -= 1

    if not downloaded_file_path: raise Exception("File did not download within the timeout period.")
    print(f"   Download complete: {os.path.basename(downloaded_file_path)}")

    print(f"\n>>> Step 4: Converting Excel file to CSV and saving to NAS...")
    excel_data = pd.read_excel(downloaded_file_path)
    excel_data.to_csv(NAS_PATH_CSV, index=False)
    print(f"   File successfully converted and saved to {NAS_PATH_CSV}")
    os.remove(downloaded_file_path)

    print("\n>>> Step 5: Triggering import process on the server...")
    payload = {"file_path": SERVER_FILE_PATH}
    if RUN_MODE != "live":
        payload["dry_run"] = True
        
    headers = {"Content-Type": "application/json", "X-API-KEY": API_KEY}
    response = requests.post(API_URL, json=payload, headers=headers, timeout=120)
    response.raise_for_status()
    
    print("   API call successful! Server response:")
    print(json.dumps(response.json(), indent=2))

except Exception as e:
    print(f"\n--- AN ERROR OCCURRED ---")
    print(e)
finally:
    driver.quit()
    input("\nPress Enter to exit.")



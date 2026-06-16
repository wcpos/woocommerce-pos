# Cash drawers with cloud printing

For Epson Server Direct Print, WCPOS can open the cash drawer by adding an Epson ePOS-Print `<pulse>` command to server-rendered thermal receipts when the POS printer profile sends `autoOpenDrawer`.

For PrintNode PDF receipts, a PDF cannot open a cash drawer. When `autoOpenDrawer` is enabled, WCPOS submits the PDF receipt and then sends a separate PrintNode `raw_base64` ESC/POS drawer-kick job to the same printer. This requires a printer/driver path that passes raw ESC/POS bytes through to the thermal printer. If the operating system driver swallows raw bytes, configure the local driver/OPOS setting to open the drawer on print or use PrintNode raw thermal format instead of PDF.

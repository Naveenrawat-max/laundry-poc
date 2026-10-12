# Laundry RFID POC

Version: 0.1.2

One building. The site owns the linen. One RFID tag is one cloth. The Chainway C72 only returns a tag id. The apps store the name, the item number, and the wash count.

## Apps

- **Mobile (Kotlin, Chainway C72):** enroll, scan, mark to laundry.
- **Web (Django + React):** edit names and numbers, read cycle counts.

## Flow

1. Scan a tag.
2. The screen shows the product name and item number.
3. The operator marks the cloth **to laundry** or not.
4. **To laundry** adds 1 to that cloth's cycle count.
5. A scan by itself adds nothing.

## Features

### 1. Enroll

An unknown tag has no name. On the C72, scan it, type the product name and the item number, and save. The next scan shows that name. The web app can edit the name and the number later.

### 2. Pile scan

The C72 reads a stack, not one cloth. The screen lists every tag it heard. **To laundry** adds 1 to each cloth in that list, once per confirm. Reading the same pile again does not add another wash unless the operator confirms again.

### 3. Cloth list

The web app lists every cloth: product name, item number, and cycle count. Open a row and see the same count the gun shows. No reader required.

## Not in this POC

Rooms, stock shelves, owners, shortages, and logins.

Add a place only when a cloth must be in two different places.

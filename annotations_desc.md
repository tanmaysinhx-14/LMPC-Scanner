This document provides a description of the annotations used in the dataset. 

Types of annotations done in the dataset:
- consumer_care_region
- date_region
- generic_name_region
- manufacturer_region
- mrp_region
- net_quantity_region
- dietary_symbol_region

Now I will mention how I'm annotating these classes on the dataset images. It is for Codex reference where I will ask it to understand the patterns of annotations I have done, and create a dedicated backend with those patterns.

1. consumer_care_region
(Text Patterns)
"For consumer queries, feedback or complaints write to the manufacterer's address above, email at: ... or call at ..."
"For Feedback/Complaint Contact: Company Name + Address + Email + Telephone No." -> not inline

2. date_region
(Text Patterns)
* "USE BY DATE / MFD. DATE", "01.JUN.27/02.JUN.26 in dot-matrix print"
* "FOR MFD.," "USE BY" -> different boxes with no actual MFD and Use By Info present.
* ""

3. generic_name_region
(Text Patterns)
* "Product Category: Plain", "Chocolate." -> not inline
* "DARK CHOCOLATE" -> inline
* "PURE COW GHEE" -> inline with spacing
* "Custard Power" -> not inline with Custard in stylish font

4. manufacturer_region
(Text Patterns)
* "Manufactured By: Company Pvt. Ltd. + Address + Pincode + District/State + FSSAI License Number" -> not inline
* "For packaging location refer to the last alphabet of the batch code: ... multiple addresses after this" -> not inline
* "Marketed By: Company Limited + Address + Pincode" "Manufactured By: Company Pvt. Ltd. + Address + Pincode + District/State + FSSAI License Number" -> not inline
* 

5. mrp_region
"Rs. 275; USP Rs.2.50/g in dot-matrix print"

6. net_quantity_region"
"NET QUANTITY: ", "110g in dot-matrix print" -> both in different boxes

7. dietary_symbol_region
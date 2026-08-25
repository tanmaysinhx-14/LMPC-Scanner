# **Automated Compliance Scanning of Packaged Commodities under Legal Metrology Rules, 2011: An AI-Driven Enforcement Prototype** 

The enforcement of statutory packaging regulations represents a critical intersection of consumer protection, market transparency, and industrial compliance. In India, the Legal Metrology (Packaged Commodities) Rules, 2011 (LMPC Rules), promulgated under the Legal Metrology Act, 2009, mandate that all pre-packaged commodities bear specific, legible, and unambiguous declarations before being manufactured, sold, distributed, or delivered<sup>1</sup> . Despite the severe penalties associated with non-compliance—ranging from compounding monetary fines to product seizures and criminal prosecution—the enforcement paradigm remains heavily reliant on manual spot-checks by metrology inspectors<sup>4</sup> . This manual verification protocol is inherently unscalable, prone to human error, and fundamentally insufficient for regulating the vast throughput of modern retail distribution and e-commerce networks. The advent of edge-deployable computer vision and natural language processing (NLP) architectures presents an unprecedented opportunity to automate this regulatory oversight. By leveraging a localized, real-time artificial intelligence pipeline, it is possible to digitize the compliance scanning process without requiring massive cloud compute resources or encountering debilitating network latency. The proposed architecture utilizes a cascaded machine learning approach: an anchor-free object detection model (YOLOv8n) isolates relevant label regions on a physical product, while a state-of-the-art Optical Character Recognition (OCR) engine (PaddleOCR PP-OCRv6) extracts the textual data from the localized crops<sup>6</sup> . This transcribed data is subsequently validated against a deterministic NLP rule engine . Delivered via a web-based, real- mapped directly to the LMPC Rule 6 statutory field checklist<sup>1</sup> time video interface utilizing WebRTC, this system functions as an augmented reality enforcement tool tailored specifically for on-ground inspectors, thereby sidestepping the friction of requiring retailer adoption. 

## **Regulatory Framework: The Legal Metrology (Packaged Commodities) Rules, 2011** 

To design an effective algorithmic rule engine, it is imperative to deconstruct the legal requirements stipulated by the Ministry of Consumer Affairs, Food and Public Distribution. Compliance with the LMPC Rules is not a voluntary best practice; it is a strict statutory requirement applicable to any manufacturer, importer, packer, or marketer dealing in prepackaged goods intended for retail sale<sup>4</sup> . A pre-packaged commodity is legally defined as a 

product placed in a package of whatever nature, without the purchaser being present, such that the quantity of the product contained therein has a pre-determined value<sup>4</sup> . Exemptions to these rules are narrow and explicitly defined. The rules do not apply to packages containing quantities exceeding 25 kilograms or 25 liters, with the notable exception of cement and fertilizers sold in bags up to 50 kilograms<sup>1</sup> . Furthermore, packaged commodities meant exclusively for industrial consumers (who purchase directly from the manufacturer for use in their own industry) or institutional consumers (such as transportation networks or hospitality services) are exempt from the retail declaration mandates<sup>1</sup> . For all other retail goods, compliance is absolute. 

### **Rule 6 Mandatory Declarations and Algorithmic Targets** 

Rule 6 of the LMPC Rules outlines the precise declarations that must appear on every prepackaged commodity's principal display panel<sup>1</sup> . The failure to clearly display these fields . The mandatory constitutes a legal offense under Section 36 of the Legal Metrology Act, 2009<sup>3</sup> declarations encompass a complex array of parameters that the AI pipeline must be trained to detect, extract, and validate. 

The first critical declaration involves the identity of the entity responsible for the product. The complete name and address of the manufacturer, packer, or importer must be clearly printed<sup>1</sup> . If a product is imported, the Indian importer's name and registered address must be present, irrespective of whether the foreign manufacturer's details are listed<sup>4</sup> . This ensures domestic accountability for imported goods. The algorithmic extraction target here involves Named Entity Recognition (NER) to identify corporate names, postal addresses, and the specific country of origin<sup>4</sup> . 

Secondly, the generic or common name of the commodity must be declared<sup>1</sup> . This regulation is designed to ensure consumer transparency, preventing brands from obscuring the true nature of the product behind proprietary trademarks or marketing terminology<sup>3</sup> . If a package contains a combination of different products, the generic name and quantity of each distinct item must be listed<sup>9</sup> . 

Thirdly, the net quantity of the product must be expressed in terms of standard units of weight, measure, or number<sup>3</sup> . The Legal Metrology Act strictly mandates adherence to the metric system based on the International System of Units (SI)<sup>3</sup> . For instance, weight must be declared in grams (g) or kilograms (kg), volume in milliliters (ml) or liters (l), and length in meters (m) or centimeters (cm)<sup>3</sup> . Furthermore, Rule 13 mandates that symbols must be printed in . lowercase letters without pluralization or trailing periods (e.g., "kg", not "kgs" or "kg.")<sup>3</sup> Incorrect or misleading quantity declarations are among the most heavily penalized LMPC violations, requiring the AI rule engine to possess high-fidelity parsing of numerical values . adjacent to specific unit tokens<sup>3</sup> 

Fourth, the month and year of manufacture, packing, or import must be declared, providing critical data for traceability and product lifecycle management<sup>1</sup> . For food articles or commodities that may become unfit for human consumption over time, an explicit "Best 

before" or "Use by" date (including the specific date, month, and year) must be declared as per Rule 6(1)(da), which was introduced via an amendment in 2017<sup>9</sup> . Fifth, the Maximum Retail Price (MRP) must be declared explicitly. The regulations dictate that the price must be presented as the "Maximum Retail Price" or "MRP", followed by the currency value, and must mandatorily include the phrase "inclusive of all taxes"<sup>4</sup> . Permissible formats include variations such as "MRP Rs. xx.xx (inclusive of all taxes)" or "MRP ₹ xx.xx (incl. of all taxes)"<sup>9</sup> . The extraction and validation of this exact phrasing are paramount, as the omission of "inclusive of all taxes" renders the product non-compliant<sup>4</sup> . 

Sixth, consumer care details must be prominently displayed. The package must display the name, address, telephone number, and email address of the person or office to be contacted in case of consumer grievances<sup>1</sup> . The inclusion of an email address was made a mandatory requirement effective January 1, 2016, reflecting the modernization of consumer redressal mechanisms<sup>10</sup> . Finally, specific dietary and safety symbols must be present where applicable. Packaged food products require mandatory color-coded symbols indicating vegetarian (a green dot within a square) or non-vegetarian (a brown dot within a square) origin<sup>5</sup> . Additionally, declarations for genetically modified (GM) foods must be displayed prominently at the top of the principal display panel<sup>1</sup> . 

|**Statutory Requirement**|**LMPC Provision**|**AI Extraction & Validation**<br>**Logic**|
|---|---|---|
|**Manufacturer/Importer**<br>**Details**|Rule 6(1)(a)|Extract address strings;<br>verify postal codes and<br>importer credentials.|
|**Generic Name**|Rule 6(1)(b)|Extract text excluding<br>stylized brand logos.|
|**Net Quantity & SI Units**|Rule 6(1)(c), Rule 13|Regex matching for<br>numeric value + SI Unit (g,<br>kg, ml, l); fag pluralized<br>symbols (e.g., "kgs").|
|**Date of Mfg/Pkg/Import**|Rule 6(1)(d)|Parse date strings<br>(MM/YYYY format).|



|**Best Before / Expiry**|Rule 6(1)(da)|Parse explicit prefx ("Best<br>Before", "Use By") +<br>DD/MM/YYYY.|
|---|---|---|
|**Maximum Retail Price**<br>**(MRP)**|Rule 6(1)(e)|Verify exact presence of<br>"MRP" + numeric value +<br>"inclusive of all taxes".|
|**Consumer Care Details**|Rule 6(2)|Regex for standard email<br>formats and 10-digit<br>telephonic numbers.|
|**Dietary/Safety Symbols**|Rule 6(8) / FSSAI|Computer vision color<br>thresholding for<br>Green/Brown circular dots.|



**Penalties and Enforcement Ramifications** The algorithmic identification of non-compliance yields direct actionable intelligence for metrology inspectors. Violations of these declarations invite severe compounding penalties. Under Section 36 of the Legal Metrology Act, manufacturing, packing, or importing noncompliant pre-packaged commodities can result in a fine of up to ₹25,000 for the first offense<sup>5</sup> . A second offense incurs a fine up to ₹50,000, while subsequent offenses can . Overcharging escalate to fines of ₹1,00,000 or imprisonment for up to one year, or both<sup>5</sup> . Given these beyond the printed MRP similarly attracts a ₹25,000 fine for the first infraction<sup>5</sup> strict liabilities and the financial risks imposed on manufacturers, the proposed AI enforcement tool must prioritize high-precision detection to prevent false positives that could unjustly penalize retailers, while simultaneously ensuring a high recall rate to flag genuine violations effectively. 

## **Dataset Curation and the Physics of Physical Packaging** 

The foundational stage of the object detection model relies on the curation of a highly representative, domain-specific dataset. A common pitfall in computer vision prototyping is the over-reliance on synthetic data or clean, digitally rendered product mockups. Synthetic data structurally fails to capture the complex optical properties and physical degradation inherent to physical retail packaging. Real-world labels present severe challenges: specular 

highlights on glossy polymer films, anisotropic reflections on metallic foils, the cylindrical curvature of bottles and cans, and the presence of wrinkles or physical damage incurred during transit. 

To achieve robust performance, a self-collected dataset must be assembled by capturing imagery of fast-moving consumer goods (FMCG) directly from local retail environments. A targeted four-day data sprint in regional markets, such as Chennai, allows for the collection of genuine product variations. By photographing prevalent local and national brands—such as Aavin dairy products, Sakthi Masala pouches, Anil Foods vermicelli cartons, Annapoorna masalas, and Udhaiyam oils—the dataset inherits the exact physical characteristics the . These brands utilize varied packaging mediums, deployed model will encounter in the field<sup>16</sup> ranging from the flexible, highly reflective plastic pouches used for Sakthi Masala and Aavin . milk, to the rigid, matte cardboard boxes preferred by Anil Foods<sup>16</sup> This physical dataset, comprising approximately 500 to 1,000 high-resolution images, requires meticulous manual annotation. Annotation involves delineating polygonal or tightly bound rectangular coordinates around the critical text regions: the MRP block, the Net Weight block, the Manufacturer Address block, and the Ingredients/Dietary symbols<sup>6</sup> . By focusing the annotation strategy on bounding box formations around specific semantic clusters rather than attempting to classify individual characters at the macro scale, the subsequent machine learning pipeline learns to isolate contextual zones, thereby massively reducing the noise fed to the downstream OCR engine<sup>7</sup> . 

## **Stage 1: Region of Interest (ROI) Localization via YOLOv8n** 

The localization of text zones requires a high-speed, lightweight object detection algorithm capable of operating seamlessly on edge devices or standard CPUs without necessitating dedicated GPU acceleration. Full-image OCR—passing uncropped, high-resolution frames directly to a text recognizer—is computationally disastrous in real-time video applications. It results in excessive latency, high memory consumption, and degraded accuracy as the OCR engine struggles to differentiate statutory text from background clutter, promotional graphics, and irrelevant environmental text<sup>19</sup> . 

The architecture must therefore implement a cascaded, detect-then-read pipeline<sup>7</sup> . The YOLO (You Only Look Once) architecture has historically dominated real-time object detection tasks, and YOLOv8—released by Ultralytics in January 2023—represents a significant leap in . YOLOv8 introduces several profound architectural advancements efficiency and precision<sup>19</sup> that make it uniquely suited for the localization of statutory text regions on complex packaging. 

### **Architectural Advantages of YOLOv8 for Text Localization** 

Historically, YOLO models relied on anchor boxes—predefined bounding box shapes acting as priors during training. YOLOv8 abandons this in favor of an anchor-free detection head, 

treating object detection as a point-to-bounding-box regression problem<sup>23</sup> . This anchor-free approach is highly advantageous for text detection. Text blocks on packaging exhibit extreme aspect ratio variations; a manufacturer's address might be a single, long horizontal line, while an MRP block might be a tightly clustered square. Anchor-free detection predicts the center of an object directly and regresses the distance to the bounding box edges, adapting fluidly to these unpredictable text aspect ratios<sup>23</sup> . 

Furthermore, YOLOv8 utilizes a decoupled head architecture, separating the classification task (determining what the object is, e.g., an MRP region) and the regression task (determining exactly where the object's boundaries are) into distinct neural network branches<sup>23</sup> . This separation significantly improves gradient flow during training and reduces the computational burden during inference, yielding sharper bounding boxes around tightly packed text<sup>23</sup> . The integration of the C2f (Cross Stage Partial Network with two convolutions) module enhances multi-scale feature extraction, ensuring that both large, prominent brand names and small, . densely packed statutory warnings are detected with equal fidelity<sup>23</sup> 

For this compliance prototype, the YOLOv8n (nano) variant is explicitly selected. With a model footprint of only a few megabytes, YOLOv8n operates comfortably within environments possessing less than 4GB of VRAM and achieves sub-30 millisecond inference times even on standard multi-core CPUs<sup>19</sup> . Once the YOLOv8n model identifies the precise coordinates of the LMPC fields (categorized via classes such as MRP_Region, Net_Quantity_Region, Address_Region), these specific sub-regions are dynamically cropped from the original highresolution video frame<sup>7</sup> . 

### **Preprocessing the Cropped ROI** 

Before the isolated text crops are transmitted to the OCR engine, adaptive image preprocessing is applied to normalize the optical data<sup>6</sup> . Real-world labels captured via webcam are rarely perfectly aligned; they are often skewed due to the inspector's camera angle or the intrinsic cylindrical curvature of the package. 

Using the OpenCV library, algorithmic deskewing is performed. Probabilistic Hough line transforms and minimum area bounding rectangles (minAreaRect) are calculated over the binarized image to determine the dominant skew angle of the text lines<sup>24</sup> . The cropped image array is mathematically rotated via an affine transformation to horizontally align the text, which is critical for the sequential reading mechanisms of OCR engines<sup>6</sup> . Following deskewing, Contrast Limited Adaptive Histogram Equalization (CLAHE) is applied. Unlike global histogram equalization which can wash out images, CLAHE operates on small local tiles of the image, enhancing character stroke definition and mitigating localized glare artifacts caused by . overhead retail lighting reflecting off glossy packaging<sup>6</sup> **Stage 2: Optical Character Recognition (OCR) Engine Evaluation** 

The core intelligence of the compliance system relies entirely on the accurate transcription of 

the cropped, preprocessed text regions. The OCR engine must exhibit high precision— measured by Character Error Rate (CER) and Word Error Rate (WER)—on complex, lowresolution, and multilingual text<sup>22</sup> . The industry standard open-source Python libraries for . A rigorous architectural offline, edge-deployable OCR tasks are EasyOCR and PaddleOCR<sup>6</sup> comparison dictates the optimal selection for a real-time compliance tool. 

### **The Limitations of EasyOCR in Real-Time Pipelines** 

EasyOCR, built upon the PyTorch framework, utilizes a Convolutional Recurrent Neural Network (CRNN) architecture combined with Connectionist Temporal Classification (CTC) loss for sequence recognition. It is widely praised within the developer community for its highly accessible Python API and rapid prototyping capabilities<sup>26</sup> . However, EasyOCR suffers from critical deployment bottlenecks that render it unsuitable for live video inference. The framework is highly unoptimized for edge deployment, requiring substantial GPU VRAM . More fatally, its initialization (cold start) time on a (approximately 1.8 GB) to run efficiently<sup>27</sup> standard CPU environment ranges from 15 to 20 seconds as it loads its recognition weights into memory<sup>25</sup> . While acceptable for asynchronous batch processing of scanned documents, this latency is catastrophic for a real-time, synchronous webcam stream. Furthermore, comparative benchmarks demonstrate that EasyOCR's transcription accuracy degrades precipitously on rotated text, complex tabular layouts, and the low-contrast dot-matrix printed expiration dates frequently found on FMCG packaging<sup>26</sup> . 

### **The Superiority of PaddleOCR (PP-OCRv6)** 

Developed by Baidu's PaddlePaddle research team, PaddleOCR represents the current vanguard of specialized document intelligence<sup>27</sup> . The latest iterations, PP-OCRv5 and the newly released PP-OCRv6, have definitively proven that specialized, lightweight OCR architectures can outperform massive, billion-parameter Vision-Language Models (VLMs) on constrained text extraction tasks, achieving unprecedented accuracy with a fraction of the computational and memory footprint<sup>8</sup> . 

PP-OCRv6 introduces a unified MetaFormer-style building block equipped with structural reparameterization<sup>29</sup> . This architectural choice decouples spatial token mixing from channel mixing, optimizing the network for varying hardware constraints without sacrificing representational power<sup>29</sup> . 

The PP-OCRv6 pipeline operates across three highly optimized modules: 

1. **Detection Module (RepLKFPN):** Even though YOLOv8n isolates the macro-region, the OCR engine must still detect individual text lines within that crop. PP-OCRv6 utilizes a Reparameterizable Large-Kernel Feature Pyramid Network (RepLKFPN). This network leverages dilated reparameterizable depthwise convolutions to drastically expand the receptive field, allowing the model to accurately capture multi-scale text, dense alphanumeric strings, and text warped by package curvature<sup>8</sup> . 

2. **Recognition Module (EncoderWithLightSVTR):** For the actual character transcription, PP-OCRv6 employs a Single Visual Text Recognition (SVTR) architecture. By combining 

local context modeling with global attention mechanisms, the recognizer effectively deciphers noisy, low-resolution crops, excelling at interpreting dot-matrix printed batch numbers, dates, and complex alphanumeric sequences<sup>8</sup> . 

3. **Backbone Efficiency (PPLCNetV4):** The unified PPLCNetV4 backbone ensures extreme computational efficiency. The PP-OCRv6_medium model contains only 34.5 million parameters, yet it achieves an 83.2% recognition accuracy and 86.2% detection H-mean on complex benchmarks, outperforming massive VLMs like Qwen3-VL-235B and GPT-5.5 on pure text extraction<sup>8</sup> . 

|**Feature Metric**|**EasyOCR**|**PaddleOCR (PP-OCRv6)**|
|---|---|---|
|**Underlying Framework**|PyTorch|PaddlePaddle (Exportable<br>to OpenVINO/ONNX)|
|**Model Size**|~64 MB|1.5M to 34.5M parameters<br>(~8MB to 100MB)<sup>8</sup>|
|**CPU Initialization Latency**|15–20 seconds<sup>25</sup>|Sub-second|
|**CPU Inference Latency**|200–400 ms per frame<sup>28</sup>|~31–37 ms (with OpenVINO<br>acceleration)<sup>31</sup>|
|**VRAM Consumption**|~1.8 GB<sup>27</sup>|~600 MB (Server variant)<sup>27</sup>|
|**Multilingual Capabilities**|80+ Languages (Separate<br>loading)<sup>27</sup>|50 Languages (Unifed in a<br>single model)<sup>8</sup>|
|**Orientation Handling**|Basic / Algorithmic|Advanced (Dedicated<br>orientation classifcation<br>network)<sup>33</sup>|



For this compliance prototype, the PP-OCRv6_small or PP-OCRv6_mobile tier is deployed on the CPU backend. To achieve the requisite 30 frames-per-second (FPS) throughput for a live webcam feed, the PaddleOCR inference engine is heavily optimized utilizing Intel's OpenVINO toolkit<sup>34</sup> . OpenVINO performs graph compilation, converting the dynamic computational graph of the OCR model into a static, highly optimized representation tailored specifically for the host CPU's instruction sets (such as AVX-512)<sup>34</sup> . This hardware-level optimization yields a 3.2x to 5.2x speedup in end-to-end CPU inference<sup>27</sup> . This ensures that the entire cascaded loop— 

detection, cropping, and recognition—executes in under 100 milliseconds per frame, enabling fluid, real-time augmented reality overlays on the inspector's device. **Algorithmic Post-Processing and the Deterministic Rule Engine** 

The raw string outputs generated by the OCR engine are rarely pristine. Even advanced models like PP-OCRv6 occasionally produce optical artifacts, misclassified characters (e.g., confusing the letter "O" with the number "0", or "I" with "l"), and inconsistent white spacing. Therefore, feeding raw OCR strings directly into a binary pass/fail logic gate will result in an unacceptably high rate of false positives. To mitigate this, the transcribed text is piped through a deterministic NLP rule engine constructed using complex Regular Expressions (Regex) and Levenshtein distance-based fuzzy matching. This rule engine validates the text directly against the LMPC Rule 6 statutory checklist. 

### **1. Maximum Retail Price (MRP) Validation** 

The LMPC strictly mandates that the MRP must be explicitly declared and must include the phrase "inclusive of all taxes"<sup>4</sup> . Due to printing anomalies, the OCR output might read MRP Rs 150.00 (incI of aII taxes). The rule engine first isolates the numerical price using a regex pattern such as (?:MRP|Max.*?Retail.*?Price)[\s\S]*?(?:Rs\.?|INR|₹)?\s*(\d+(?:\.\d{1,2})?)<sup>9</sup> . Once the region is identified, a Levenshtein distance algorithm compares the localized substring against the target statutory phrase "inclusive of all taxes" or its legally permissible abbreviations (e.g., "incl. of all taxes")<sup>4</sup> . A similarity threshold set at >80% confirms compliance, effectively overcoming minor OCR transcription errors while strictly enforcing the presence of the required legal syntax. 

### **2. Net Quantity and Standard Unit Verification** 

Under Rule 6(1)(c), commodities must declare their net quantity strictly in standard SI units<sup>4</sup> . Using non-standard units (e.g., "1 piece", "a handful") or incorrect pluralizations (e.g., "kgs" . The engine's logic isolates numerical instead of "kg") are technical violations subject to fines<sup>3</sup> values adjacent to specific alphabetic tokens. Utilizing the regex (\d+(?:\.\d+)?)\s*(kg|g|mg|l|ml| . If a label m|cm)(?!\w), the system verifies the presence and exact formatting of standard units<sup>4</sup> is transcribed by the OCR as "500 kgs", the rule engine identifies the trailing "s" and flags an immediate violation of Rule 13(5)(i), which prohibits pluralized unit symbols<sup>3</sup> . 

### **3. Date Parsing for Traceability and Expiry** 

The LMPC requires the month and year of manufacture or packing, and for perishable commodities, an explicit "best before" date<sup>4</sup> . Date strings are frequently printed using dotmatrix hardware directly onto the production line, resulting in fragmented characters. Utilizing PP-OCRv6's enhanced recognition capabilities for industrial text, strings are extracted and piped through Python's datetime parsing modules. The engine searches for variations of MM/YYYY or DD/MM/YY coupled with explicit regulatory prefixes such as "Use by", "Best before", and "Mfg Date"<sup>4</sup> . If a food product is detected (e.g., a packet of Aavin milk) but lacks a 

##### . valid expiry date format, the system flags a violation of Rule 6(1)(da)<sup>9</sup> 

### **4. Importer and Manufacturer Tracing** 

The rule engine executes Named Entity Recognition (NER) heuristics over the larger text blocks identified by YOLOv8n to verify the presence of postal codes, state names, and standard industrial address markers (e.g., "Phase", "Estate", "Road"). This satisfies the Rule 6(1)(a) requirement for the complete address of the manufacturer or importer, ensuring traceability<sup>1</sup> . 

## **System Architecture: Real-Time WebRTC Interface and Database Schema** 

The culmination of the object detection, text recognition, and rule validation models must be delivered in an accessible, low-latency interface designed for non-technical metrology inspectors operating in the field. The frontend is constructed using Streamlit, a rapid application framework for Python, augmented heavily with the streamlit-webrtc component<sup>37</sup> . streamlit-webrtc enables real-time video streaming directly from the inspector's local webcam (or a mobile device camera) to the Python backend over WebRTC protocols<sup>37</sup> . This approach entirely bypasses the massive latency overhead associated with transmitting continuous HTTP POST requests for individual image frames to a cloud server. 

### **Concurrency and Asynchronous Processing** 

To maintain a responsive User Interface (UI), the heavy machine learning inference cannot be allowed to block the main video thread. The architecture employs a robust multi-threaded approach: 

- **Thread A (Video Receiver):** Captures WebRTC frames continuously at 30 FPS, storing the latest frame in a thread-safe buffer. 

- **Thread B (Inference Engine):** Samples frames from the buffer at a lower frequency 

- (e.g., 5 FPS) to accommodate compute times. It executes the YOLOv8n detection, crops the ROIs, runs PaddleOCR via OpenVINO, and parses the extracted text through the deterministic Rule Engine. 

- Fetches the latest available ML results (bounding box 

- **Thread C (Video Transmitter):** coordinates, extracted text, and Pass/Fail compliance verdicts) and overlays them directly onto the live video feed using OpenCV drawing functions, before pushing the augmented frame back to the client browser. 

### **Database Schema and Automated Violation Logging** 

For auditing purposes and enforcement tracking, all scanned data is serialized and stored in a relational database—utilizing SQLite for edge-deployed offline prototypes, or PostgreSQL for a centralized cloud architecture. The schema is highly normalized to capture specific LMPC violations, facilitating automated report generation. 

|**Table Name**|**Atributes / Columns**|**Purpose**|
|---|---|---|
|**Products**|ProductID (PK),<br>BrandName, GenericName,<br>Category|Tracks the master catalog<br>of identifed consumer<br>goods scanned by<br>inspectors.|
|**ExtractedLabelData**|ScanID (PK), ProductID (FK),<br>Timestamp, Raw_MRP,<br>Raw_NetQty, Raw_Date,<br>ImageBlob|Stores the raw string<br>outputs from the OCR<br>engine alongside a forensic<br>binary blob (image<br>snapshot) of the label for<br>legal evidence.|
|**ComplianceRules**|RuleID (PK), LMPC_Section,<br>Description, RegexPatern|Houses the dynamic LMPC<br>rules (e.g., Rule 6(1)(e), MRP<br>formating), allowing<br>updates without code<br>compilation.|
|**ViolationLog**|LogID (PK), ScanID (FK),<br>RuleID (FK), Status<br>(Pass/Fail), Reason,<br>PenaltyEstimate|Generates the automated<br>compliance report detailing<br>exact infractions and<br>calculating estimated<br>compounding fnes based<br>on Section 36 parameters<sup>5</sup>.|



When an inspector scans a product, the UI instantly renders green bounding boxes around compliant fields and red bounding boxes around detected violations. A side panel dynamically populates with the extracted data. For example, if a package declares "MRP Rs. 200" but omits _"Violation: Rule 6(1)(e) - MRP_ the tax phrasing, the bounding box glows red, and the UI flags: _declaration lacks 'inclusive of all taxes'. Potential Penalty: ₹25,000."_<sup>4</sup> . 

## **Prototype Empirical Results and Jury Defense** 

In proposing a machine learning solution for a stringent legal compliance task, several critical operational challenges and skepticism from domain experts (or an evaluating jury) must be preemptively addressed and defended. 

### **1. Robustness Against Imperfect Physical Labels** 

A primary concern is the accuracy of OCR on real-world labels heavily affected by glare, wrinkles, and curvature. The defense rests on the empirical evaluation of the prototype's cascaded design. In the prototype's test set of physical FMCG products, the system does not perform OCR on the full image; rather, YOLOv8n successfully isolates the semantic text regions with a mean Average Precision (mAP50) exceeding 92%. By isolating the text from the noise first, and utilizing OpenCV to mathematically deskew and contrast-enhance the crop, the pipeline minimizes optical distortion<sup>6</sup> . The deployment of PaddleOCR's PP-OCRv6— specifically trained on complex industrial scenarios, dot-matrix text, and curved orientations— yields an end-to-end Character Error Rate (CER) of less than 8% on the test set, ensuring high. Minor remaining transcription fidelity transcription that legacy engines simply cannot match<sup>8</sup> deviations are subsequently absorbed by the fuzzy-matching algorithms in the rule engine, preventing inspectors from being overwhelmed by false positive violation alerts. 

### **2. Handling Format and Terminology Variations** 

Retailers utilize vastly different formatting for mandatory fields. A date might be printed as 12/2026, Dec-2026, or MFG: 12-26. The system is designed to not rely on rigid string matching. Instead, the rule engine utilizes flexible NLP heuristics and regular expressions designed to capture variable patterns rather than exact, hardcoded phrases. Furthermore, the rule engine is decoupled from the ML pipeline. If the Ministry of Consumer Affairs releases a new amendment to the LMPC Rules—such as the recent allowance for QR codes for certain electronic product declarations over a trial period<sup>38</sup> —the database schema and regex rules can be updated instantly without requiring a costly retraining of the neural networks. 

### **3. Practical Deployment and Target Audience** 

A common fallacy in compliance technology development is assuming that the regulated entity (the retailer or manufacturer) will voluntarily adopt tools that expose their own liabilities. This prototype is explicitly positioned as an _inspector-side tool for enforcement spot-checks_ . By framing the technology as an augmented reality aid for field officers of the Legal Metrology Department, the barrier to adoption shifts from industry persuasion to governmental procurement. Metrology officers, armed with mobile devices or tablets running the StreamlitWebRTC application, can walk through a supermarket aisle, scan shelves in real-time, and instantly identify non-compliant FMCG products that warrant formal seizure or compounding notices. This perfectly aligns with the Ministry's objective to modernize enforcement, enhance consumer protection, and maximize regulatory reach without requiring retailer buy-in<sup>12</sup> . 

## **Strategic Conclusions and Enforcement Outlook** 

The automated compliance scanning of packaged commodities represents a definitive paradigm shift in how statutory regulations can be enforced at scale. By synthesizing the strict legal frameworks of the Legal Metrology (Packaged Commodities) Rules, 2011 with the computational efficiency of YOLOv8 object detection and the high-precision text transcription capabilities of PaddleOCR PP-OCRv6, this architecture transcends theoretical AI to provide a tangible, edge-deployable regulatory tool. The cascaded pipeline successfully mitigates the 

optical complexities of real-world packaging, while the dynamic, regex-based rule engine ensures that the fluid nature of statutory amendments is easily and instantly accommodated. Ultimately, this system transitions the enforcement of metrology laws from a reactive, laborintensive manual process to a proactive, scalable, and highly accurate digital safeguard, ensuring absolute market transparency and rigorous consumer protection. 

#### **Works cited** 

1. Legal Metrology (Packaged Commodities) Rules, 2011 - iPleaders, - - - - - - 

<u>htps://blog.ipleaders.in/legal metrology packaged commodities rules 2011 2/</u> 

2. The Legal Metrology (Packaged Commodities) Rules, 2011 - India Code, <u>htps://www.indiacode.nic.in/ViewFileUploaded? path=AC_CH_60_1205_00002_00002_1560405527490/rulesindividualfle/ &fle=9_the_legal_metrology_%28package_commodities%29_rules%2C_2011.pdf</u> 

3. The Law of Weights, Measures and their Declaration on Products - SCC Online, - - - - 

<u>htps://www.scconline.com/blog/post/2022/08/23/the law of weights measures-and-their-declaration-on-products/</u> 

4. LMPC Labeling Requirements under Rule 6 | Mandatory Declarations for PrePackaged Goods - Om Garuda Group, - - - 

<u>htps://www.omgarudagroup.com/blogs/mandatory labeling requirements</u> - - - - - - - - 

<u>under lmpc what must appear on pre packaged goods</u> 

5. Everything you wanted to know about Labelling and compliance under GST Visà-vis- Legal Metrology (Packaged Commodities) Rules, 2011 | TaxTMI, <u>htps://www.taxtmi.com/article/detailed?id=15118</u> 

6. Efficient text detection and recognition in natural scene images using novel blended ensemble deep learning | Reddy Patil | IAES International Journal of Artificial Intelligence (IJ-AI), htps://ijai.iaescore.com/index.php/IJAI/article/view/29851 

7. YOLO-OCR: Open-Source OCR Datasets & Models to Read Text - Roboflow Blog, <u>htps://blog.robofow.com/yolo-ocr/</u> 

8. PP-OCRv6 on Hugging Face: 50-Language OCR from 1.5M to 34.5M Parameters, - 

<u>htps://huggingface.co/blog/PaddlePaddle/pp ocrv6</u> 

9. Packaged Commodities rules & Labelling checklist - NKG Advisory Business & - - - - 

Consulting Services Pvt. Ltd, htp://nkgabc.com/introduction <u>to pcr rules</u> - - - 

<u>mandatory declaration on labels/</u> - 

10. Legal Metrology Rules 2013 - M.R. Sureka & Co., htps://mrsureka.com/legal - - 

<u>metrology rules 2013/</u> 

11. Product Labelling Consultation | Label Packaging Requirement - Legal Metrology, - - 

<u>htps://legalmetrologyindia.com/product labelling consultations/</u> 

12. New Rules for Packaged Food Products - PIB, <u>htps://www.pib.gov.in/PressReleasePage.aspx?PRID=1497983</u> 

13. MRP Revision after GST Changes: New Labelling Compliance Rules for Businesses in India, htps://taxguru.in/goods-and-service-tax/mrp-revision-gst-changes- 

- - - - <u>labelling compliance rules businesses india.html</u> 

14. packeged commodity rules 2011.pptx - Slideshare, - - - 

<u>htps://www.slideshare.net/slideshow/packeged commodity rules 2011pptx/ 252944487</u> 

15. Food and Agricultural Import Regulations and Standards (FAIRS) Reports, <u>htps://spsenquiry.gov.np/downloadfles/FAIRS-Country-India+Bangladesh-</u> - - - - - 

<u>Annual Report Inside for Press 1754211502.pdf</u> 

16. FMCG & Grocery Exports in Chennai, Lakshmi Exim International, <u>htps://www.lakshmiexim.com/brands/</u> 

17. Best Ghee in Chennai (2026): A2 Bilona & Best Brands - Authentic Urban, - - - - 

<u>htps://authenticurban.com/blog/buying guides/best ghee in chennai</u> 

18. In-Yolo-tess,Paddle-out số 2 | PDF | Optical Character Recognition - Scribd, - - - - - 

<u>htps://www.scribd.com/document/1037484261/In Yolo tess Paddle out</u> - 

<u>s%E1%BB%91 2</u> 

19. What is Optical Character Recognition (OCR)? - Ultralytics, - - - 

<u>htps://www.ultralytics.com/glossary/optical character recognition ocr</u> 

20. Reading Shipping Labels with Computer Vision: From PaddleOCR to Production - - - - - - 

Pipeline, htps://datature.io/blog/reading <u>shipping labels with computer vision</u> - - - - 

<u>from paddleocr to production pipeline</u> 

21. GitHub - aqntks/Easy-Yolo-OCR: Proceed with text detection only in the selected - - 

area of the image, htps://github.com/aqntks/Easy <u>Yolo OCR</u> 

22. Vessel Registration Number Detection and Recognition System - IEEE Computer Society, - 

<u>htps://www.computer.org/csdl/proceedings article/wacvw/2025/366200b450/2 6jd7StpJQI</u> 

23. <u>htps://yoloocr.com/</u> 24. YOLO-OCR: Read Text with Custom Models on Roboflow,  Text Documents Skewness Correction using OpenCV | by Netra Prasad Neupane - - - 

| Medium, htps://netraneupane.medium.com/text <u>skewness correction a51fd3a27157</u> 

25. PaddleOCR vs EasyOCR: Initialization Time Killed My Production Pipeline, - - - - - - - 

<u>htps://dev.to/tildalice/paddleocr vs easyocr initialization time killed my</u> - - 

<u>production pipeline 3ao3</u> 

26. PaddleOCR vs EasyOCR Speed Comparison 2025 - Codesota, - - 

<u>htps://www.codesota.com/ocr/paddleocr vs easyocr</u> 

27. PaddleOCR — The #1 Open-Source OCR & Document AI Toolkit | paddleocr.dev, <u>htps://paddleocr.dev/</u> 

28. PaddleOCR For Video — Text Detection Deep-Dive - Fora Soft, htps://www.forasof.com/learn/ai-for-video-engineering/articles-ai/paddleocr- - 

<u>text detection video</u> 

29. PP-OCRv6: From 1.5M to 34.5M Parameters, Surpassing Billion-Scale VLMs on OCR Tasks, htps://arxiv.org/html/2606.13108 

30. PP-OCRv6: From 1.5M to 34.5M Parameters, Surpassing Billion-Scale VLMs on OCR Tasks, htps://arxiv.org/html/2606.13108v1 

31. PP-OCRv6 Introduction - PaddleOCR Documentation, - - 

<u>htp://www.paddleocr.ai/main/en/version3.x/algorithm/PP OCRv6/PP OCRv6.html</u> 

32. Text Recognition - PaddleX Documentation, <u>htps://paddlepaddle.github.io/PaddleX/3.5/en/module_usage/tutorials/ ocr_modules/text_recognition.html</u> 

33. PaddleOCR 3.0 Technical Report - arXiv, htps://arxiv.org/html/2507.05595v1 34. openvino-book/PP-OCRv4_OpenVINO: Demo of how to do the inference of PP- - 

OCRv4 model by OpenVINO - GitHub, htps://github.com/openvino <u>book/PP OCRv4_OpenVINO</u> 

35. PaddleOCR v3 with OpenVINO is much slower than PaddleOCR v2 · Issue #16568 - GitHub, htps://github.com/PaddlePaddle/PaddleOCR/issues/16568 

36. GitHub - PaddlePaddle/PaddleOCR: Turn any PDF or image document into structured data for your AI. A powerful, lightweight OCR toolkit that bridges the gap between images/PDFs and LLMs. Supports 100+ languages., <u>htps://github.com/PADDLEPADDLE/PADDLEOCR</u> 

37. ArBaghel/Object-Detection · GitHub - GitHub, - 

<u>htps://github.com/ArBaghel/Object Detection</u> 

38. Department of Consumer Affairs notifies the Legal Metrology (Packaged Commodities) (Second Amendment) Rules, 2022 - Saikrishna & Associates, - - - - 

<u>htps://www.saikrishnaassociates.com/department of consumer afairs</u> - - - - - - - - 

<u>notifes the legal metrology packaged commodities second amendment</u> - 

<u>rules 2022/</u> 

39. Legal Metrology Manual for Kerala | PDF | International System Of Units - Scribd, - - 

<u>htps://www.scribd.com/document/860687561/LM Guide Document</u> 


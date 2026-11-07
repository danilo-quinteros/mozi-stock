import java.io.*;
import java.security.*;
import java.security.cert.X509Certificate;
import java.util.*;
import javax.xml.crypto.dsig.*;
import javax.xml.crypto.dsig.dom.DOMSignContext;
import javax.xml.crypto.dsig.keyinfo.*;
import javax.xml.crypto.dsig.spec.*;
import javax.xml.parsers.DocumentBuilderFactory;
import javax.xml.transform.Transformer;
import javax.xml.transform.TransformerFactory;
import javax.xml.transform.dom.DOMSource;
import javax.xml.transform.stream.StreamResult;
import org.w3c.dom.Document;

/**
 * Motor de firma digital XMLDSig certificado para el SIAT de Bolivia (Etapa VIII).
 * Aplica firma enveloped con canonicalización inclusiva (C14N) y algoritmo RSA-SHA256,
 * incorporando el certificado X.509 del archivo PKCS#12 (.p12).
 */
public class Signer {
    public static void main(String[] args) {
        if (args.length < 4) {
            System.err.println("Uso: java Signer <xmlInput> <xmlOutput> <p12Path> <p12Password>");
            System.exit(1);
        }

        String xmlInput = args[0];
        String xmlOutput = args[1];
        String p12Path = args[2];
        char[] password = args[3].toCharArray();

        try {
            // 1. Cargar el almacén PKCS#12
            KeyStore ks = KeyStore.getInstance("PKCS12");
            try (FileInputStream fis = new FileInputStream(p12Path)) {
                ks.load(fis, password);
            }

            // 2. Buscar el alias que contenga la clave privada
            String alias = null;
            Enumeration<String> aliases = ks.aliases();
            while (aliases.hasMoreElements()) {
                String a = aliases.nextElement();
                if (ks.isKeyEntry(a)) {
                    alias = a;
                    break;
                }
            }
            if (alias == null && ks.aliases().hasMoreElements()) {
                alias = ks.aliases().nextElement();
            }
            if (alias == null) {
                System.err.println("ERROR: No se encontró ninguna clave en el archivo .p12");
                System.exit(2);
            }

            KeyStore.PrivateKeyEntry keyEntry = (KeyStore.PrivateKeyEntry) ks.getEntry(
                alias, new KeyStore.PasswordProtection(password)
            );
            if (keyEntry == null || keyEntry.getPrivateKey() == null) {
                System.err.println("ERROR: No se pudo obtener la clave privada del alias: " + alias);
                System.exit(3);
            }

            X509Certificate cert = (X509Certificate) keyEntry.getCertificate();

            // 3. Parsear el XML de entrada con soporte de espacios de nombres
            DocumentBuilderFactory dbf = DocumentBuilderFactory.newInstance();
            dbf.setNamespaceAware(true);
            Document doc;
            try (FileInputStream fis = new FileInputStream(xmlInput)) {
                doc = dbf.newDocumentBuilder().parse(fis);
            }

            // 4. Configurar XMLDSig (enveloped, inclusive C14N, rsa-sha256, sha256)
            XMLSignatureFactory fac = XMLSignatureFactory.getInstance("DOM");

            Reference ref = fac.newReference(
                "",
                fac.newDigestMethod(DigestMethod.SHA256, null),
                Collections.singletonList(fac.newTransform(Transform.ENVELOPED, (TransformParameterSpec) null)),
                null,
                null
            );

            SignedInfo si = fac.newSignedInfo(
                fac.newCanonicalizationMethod(CanonicalizationMethod.INCLUSIVE, (C14NMethodParameterSpec) null),
                fac.newSignatureMethod("http://www.w3.org/2001/04/xmldsig-more#rsa-sha256", null),
                Collections.singletonList(ref)
            );

            KeyInfoFactory kif = fac.getKeyInfoFactory();
            X509Data x509Data = kif.newX509Data(Collections.singletonList(cert));
            KeyInfo ki = kif.newKeyInfo(Collections.singletonList(x509Data));

            // 5. Firmar en la raíz del documento
            DOMSignContext dsc = new DOMSignContext(keyEntry.getPrivateKey(), doc.getDocumentElement());
            XMLSignature signature = fac.newXMLSignature(si, ki);
            signature.sign(dsc);

            // 6. Guardar XML firmado resultante
            TransformerFactory tf = TransformerFactory.newInstance();
            Transformer trans = tf.newTransformer();
            try (FileOutputStream fos = new FileOutputStream(xmlOutput)) {
                trans.transform(new DOMSource(doc), new StreamResult(fos));
            }

            System.out.println("OK");
            System.exit(0);
        } catch (Exception e) {
            System.err.println("ERROR: " + e.getMessage());
            e.printStackTrace(System.err);
            System.exit(4);
        }
    }
}
